<?php

// namespace App\Serveces;

// use App\Exceptions\BookingException;
// use App\Models\Booking;
// use App\Models\Resource;
// use App\Models\User;
// use Carbon\Carbon;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Str;

// class BookingService
// {
//     /**
//      * Create a new class instance.
//      */
//     const MIN_DURATION_MINUTES = 30;
//     const MAX_DURATION_HOURS = 8;
//     const MAX_ACTIVE_BOOKINGS = 5;

//     /**
//      * Create a new booking
//      */
//     public function createBooking(User $user, array $data): Booking
//     {
//         $startTime = Carbon::parse($data['start_time']);
//         $endTime = Carbon::parse($data['end_time']);
//         $resourceId = $data['resource_id'];

//         // Validate booking rules
//         $this->validateBookingTimes($startTime, $endTime);
//         $this->validateUserBookingLimit($user);
//         $resource = $this->validateResourceAvailability($resourceId, $startTime, $endTime);

//         // Create booking
//         return $user->bookings()->create([
//             'booking_reference' => $this->generateBookingReference(),
//             'resource_id' => $resourceId,
//             'start_time' => $startTime,
//             'end_time' => $endTime,
//             'status' => 'approved',
//             'purpose' => $data['purpose'] ?? null,
//         ]);
//     }

//     /**
//      * Update an existing booking
//      */
//     public function updateBooking(Booking $booking, array $data): Booking
//     {
//         // Only allow updates to future bookings
//         if ($booking->start_time <= Carbon::now()) {
//             throw new BookingException('Cannot modify bookings that have already started.');
//         }

//         $startTime = isset($data['start_time']) ? Carbon::parse($data['start_time']) : $booking->start_time;
//         $endTime = isset($data['end_time']) ? Carbon::parse($data['end_time']) : $booking->end_time;

//         // If times are being changed, validate them
//         if (isset($data['start_time']) || isset($data['end_time'])) {
//             $this->validateBookingTimes($startTime, $endTime);
//             $this->validateResourceAvailability($booking->resource_id, $startTime, $endTime, $booking->id);
//         }

//         $booking->update([
//             'start_time' => $startTime,
//             'end_time' => $endTime,
//             'purpose' => $data['purpose'] ?? $booking->purpose,
//         ]);

//         return $booking->fresh();
//     }

//     /**
//      * Cancel a booking
//      */
//     public function cancelBooking(Booking $booking): void
//     {
//         // Only allow cancellation of future bookings
//         if ($booking->start_time <= Carbon::now()) {
//             throw new BookingException('Cannot cancel bookings that have already started.');
//         }

//         $booking->update(['status' => 'cancelled']);
//     }

//     /**
//      * Validate booking start and end times
//      */
//     private function validateBookingTimes(Carbon $startTime, Carbon $endTime): void
//     {
//         // Start time must be in the future
//         if ($startTime <= Carbon::now()) {
//             throw new BookingException('Booking start time must be in the future.');
//         }

//         // End time must be after start time
//         if ($endTime <= $startTime) {
//             throw new BookingException('End time must be greater than start time.');
//         }

//         // Check duration constraints
//         $durationInMinutes = $endTime->diffInMinutes($startTime);

//         if ($durationInMinutes < self::MIN_DURATION_MINUTES) {
//             throw new BookingException('Booking duration must be at least ' . self::MIN_DURATION_MINUTES . ' minutes.');
//         }

//         if ($durationInMinutes > (self::MAX_DURATION_HOURS * 60)) {
//             throw new BookingException('Booking duration cannot exceed ' . self::MAX_DURATION_HOURS . ' hours.');
//         }
//     }

//     /**
//      * Validate user hasn't exceeded booking limit
//      */
//     private function validateUserBookingLimit(User $user): void
//     {
//         $activeBookingsCount = $user->bookings()
//             ->whereIn('status', ['approved', 'pending'])
//             ->where('end_time', '>', Carbon::now())
//             ->count();

//         if ($activeBookingsCount >= self::MAX_ACTIVE_BOOKINGS) {
//             throw new BookingException('You have reached the maximum limit of ' . self::MAX_ACTIVE_BOOKINGS . ' active bookings.');
//         }
//     }

//     /**
//      * Validate resource availability
//      */
//     private function validateResourceAvailability(int $resourceId, Carbon $startTime, Carbon $endTime, ?int $excludeBookingId = null): Resource
//     {
//         $resource = Resource::find($resourceId);

//         if (!$resource) {
//             throw new BookingException('Resource not found.');
//         }

//         if (!$resource->is_active) {
//             throw new BookingException('The selected resource is currently not active.');
//         }

//         // Check for overlapping bookings
//         $query = Booking::where('resource_id', $resourceId)
//             ->where('status', '!=', 'cancelled')
//             ->where(function ($query) use ($startTime, $endTime) {
//                 $query->where(function ($q) use ($startTime, $endTime) {
//                     // New booking starts during existing booking
//                     $q->where('start_time', '<=', $startTime)
//                       ->where('end_time', '>', $startTime);
//                 })->orWhere(function ($q) use ($startTime, $endTime) {
//                     // New booking ends during existing booking
//                     $q->where('start_time', '<', $endTime)
//                       ->where('end_time', '>=', $endTime);
//                 })->orWhere(function ($q) use ($startTime, $endTime) {
//                     // New booking completely encompasses existing booking
//                     $q->where('start_time', '>=', $startTime)
//                       ->where('end_time', '<=', $endTime);
//                 });
//             });

//         if ($excludeBookingId) {
//             $query->where('id', '!=', $excludeBookingId);
//         }

//         $overlappingBookings = $query->count();

//         if ($overlappingBookings > 0) {
//             throw new BookingException('The resource is not available during the requested time slot.');
//         }

//         return $resource;
//     }

//     /**
//      * Generate a unique booking reference
//      */
//     private function generateBookingReference(): string
//     {
//         do {
//             $reference = 'BK-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
//         } while (Booking::where('booking_reference', $reference)->exists());

//         return $reference;
//     }

//     /**
//      * Cancel multiple bookings for a user
//      */
//     public function cancelMultipleBookings(array $bookingIds, $userId, $reason = null)
//     {
//         return DB::transaction(function () use ($bookingIds, $userId, $reason) {
//             $bookings = Booking::whereIn('id', $bookingIds)
//                 ->where('user_id', $userId)
//                 ->active()
//                 ->notExpired()
//                 ->get();

//             $cancelledCount = 0;
//             $errors = [];

//             foreach ($bookings as $booking) {
//                 if ($booking->canBeCancelled()) {
//                     $booking->update([
//                         'status' => Booking::STATUS_CANCELLED,
//                         'cancelled_at' => Carbon::now(),
//                         'cancellation_reason' => $reason
//                     ]);
//                     $cancelledCount++;
//                 } else {
//                     $errors[] = "Booking #{$booking->id} cannot be cancelled";
//                 }
//             }

//             return [
//                 'cancelled_count' => $cancelledCount,
//                 'total_requested' => count($bookingIds),
//                 'errors' => $errors
//             ];
//         });
//     }

//     /**
//      * Get cancellation statistics for a user
//      */
//     public function getCancellationStats($userId)
//     {
//         $stats = Booking::where('user_id', $userId)
//             ->selectRaw('
//                 COUNT(*) as total_bookings,
//                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_bookings,
//                 SUM(CASE WHEN status = ? AND cancelled_at >= ? THEN 1 ELSE 0 END) as recent_cancellations
//             ', [
//                 Booking::STATUS_CANCELLED,
//                 Booking::STATUS_CANCELLED,
//                 Carbon::now()->subDays(30)
//             ])
//             ->first();

//         return $stats;
//     }
// }

// app/Services/BookingService.php

// app/Services/BookingService.php

// namespace App\Services; 

// use App\Models\Booking;
// use App\Models\Resource;
// use App\Models\User; 
// use Carbon\Carbon;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use App\Notifications\BookingApproved;
// use App\Notifications\BookingRejected;
// use App\Notifications\BookingPreempted;
// use Illuminate\Support\Str; 

// class BookingService
// {
//     /**
//      * Assigns priority level based on user type and booking type.
//      *
//      * @param User $user
//      * @param string $bookingType
//      * @return int
//      */
//     private function determinePriority(User $user, string $bookingType): int
//     {
//         switch ($bookingType) {
//             case 'university_activity':
//                 return 4; // Highest priority
//             case 'class':
//                 // Only staff (lecturers) can typically book classes
//                 return ($user->user_type === 'staff') ? 3 : 0;
//             case 'staff_meeting':
//                 return ($user->user_type === 'staff') ? 2 : 0;
//             case 'student_meeting':
//                 return ($user->user_type === 'student') ? 1 : 0;
//             default:
//                 return 0; // Default for 'other' or mismatch
//         }
//     }

//     /**
//      * Finds conflicting bookings for a given time slot and resource.
//      *
//      * @param int $resourceId
//      * @param Carbon $startTime
//      * @param Carbon $endTime
//      * @param int|null $excludeBookingId
//      * @return \Illuminate\Support\Collection
//      */
//     public function findConflictingBookings(int $resourceId, Carbon $startTime, Carbon $endTime, ?int $excludeBookingId = null): \Illuminate\Support\Collection
//     {
//         $query = Booking::where('resource_id', $resourceId)
//             ->whereIn('status', ['pending', 'approved']) // Only consider active bookings for conflicts
//             ->where(function ($q) use ($startTime, $endTime) {
//                 // Check for overlapping intervals
//                 $q->where('start_time', '<', $endTime)
//                   ->where('end_time', '>', $startTime);
//             });

//         if ($excludeBookingId) {
//             $query->where('id', '!=', $excludeBookingId);
//         }

//         // Eager load the user to access user_type and name for notifications
//         return $query->with('user:id,first_name,last_name,email,user_type')->get();
//     }

//     /**
//      * Handles the creation and scheduling of a new booking request with priority logic.
//      *
//      * @param array $data
//      * @param User $user
//      * @return array
//      */
//     public function createBooking(array $data, User $user): array
//     {
//         DB::beginTransaction();

//         try {
//             $resource = Resource::find($data['resource_id']);
//             if (!$resource) {
//                 DB::rollBack();
//                 return ['success' => false, 'message' => 'Resource not found.', 'status_code' => 404];
//             }

//             if (!$resource->is_active) {
//                 DB::rollBack();
//                 return ['success' => false, 'message' => 'The selected resource is currently not active.', 'status_code' => 422];
//             }

//             $startTime = Carbon::parse($data['start_time']);
//             $endTime = Carbon::parse($data['end_time']);

//             // Determine priority for the new booking
//             $newBookingPriority = $this->determinePriority($user, $data['booking_type']);

//             $conflictingBookings = $this->findConflictingBookings(
//                 $data['resource_id'],
//                 $startTime,
//                 $endTime
//             );

//             // Filter out bookings that cannot be preempted
//             $preemptableConflicts = $conflictingBookings->filter(function ($conflict) use ($newBookingPriority) {
//                 // A booking can be preempted if the new booking's priority is strictly higher
//                 return $newBookingPriority > $conflict->priority_level;
//             });

//             // Count non-preemptable conflicts
//             $nonPreemptableConflicts = $conflictingBookings->filter(function ($conflict) use ($newBookingPriority) {
//                 // A booking is not preemptable if its priority is equal to or higher than the new booking
//                 return $newBookingPriority <= $conflict->priority_level;
//             });

//             // Check if resource capacity is exceeded by non-preemptable bookings
//             // For capacity 1 resources, if there's any non-preemptable conflict, it's a hard conflict.
//             if ($resource->capacity == 1 && $nonPreemptableConflicts->isNotEmpty()) {
//                 DB::rollBack();
//                 return ['success' => false, 'message' => 'The resource is not available during the requested time slot due to a higher priority booking.', 'status_code' => 409];
//             }

//             // For resources with capacity > 1, check if the combined count of new booking + non-preemptable conflicts exceeds capacity
//             if ($resource->capacity > 1 && ($nonPreemptableConflicts->count() + 1 > $resource->capacity)) {
//                  DB::rollBack();
//                 return ['success' => false, 'message' => 'Resource capacity is fully booked for the selected time period by higher or equal priority bookings.', 'status_code' => 409];
//             }


//             // All checks passed, we can proceed.
//             // First, preempt lower priority bookings
//             foreach ($preemptableConflicts as $preemptedBooking) {
//                 $preemptedBooking->status = 'preempted'; // A new status for clarity
//                 $preemptedBooking->cancellation_reason = 'Preempted by higher priority booking (Ref: ' . $data['booking_reference'] . ')';
//                 $preemptedBooking->cancelled_at = Carbon::now();
//                 $preemptedBooking->save();

//                 // Notify the user whose booking was preempted
//                 if ($preemptedBooking->user) {
//                     $preemptedBooking->user->notify(new BookingPreempted($preemptedBooking));
//                 }
//             }

//             // Create the new booking
//             $booking = $user->bookings()->create([
//                 "booking_reference" => $this->generateBookingReference(),
//                 "resource_id" => $data['resource_id'],
//                 "start_time" => $startTime,
//                 "end_time" => $endTime,
//                 "status" => "approved", // New high-priority booking is approved immediately
//                 "purpose" => $data['purpose'],
//                 "booking_type" => $data['booking_type'],
//                 "priority" => $newBookingPriority,
//             ]);

//             // Notify the user who made the new booking
//             $user->notify(new BookingApproved($booking));

//             DB::commit();

//             return [
//                 'success' => true,
//                 'message' => 'Booking created successfully.',
//                 'booking' => $booking->load('resource'),
//                 'status_code' => 201
//             ];

//         } catch (\Exception $e) {
//             DB::rollBack();
//             Log::error('Booking creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
//              return ['success' => false, 'message' => 'An error occurred while creating the booking.', 'status_code' => 500];
//         }
//     }

//     /**
//      * Generate a unique booking reference.
//      * Moved from controller to service for consistency.
//      */
//     private function generateBookingReference(): string
//     {
//         do {
//             $reference = 'MZUNI-RBA-' . now()->format('dmHi') . '-' . strtoupper(Str::random(4));
//         } while (Booking::where('booking_reference', $reference)->exists());

//         return $reference;
//     }
// }


namespace App\Services;

use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\BookingApproved;
use App\Notifications\BookingRejected;
use App\Notifications\BookingPreempted;
use Illuminate\Support\Str;
use App\Exceptions\BookingException; // Ensure this is correctly imported

class BookingService
{
    // Define constants for booking rules and statuses
    const MIN_DURATION_MINUTES = 30;
    const MAX_DURATION_HOURS = 8;
    const MAX_ACTIVE_BOOKINGS = 5; // Maximum active bookings per user

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_IN_USE = 'in_use'; // Status for when a resource is currently being used
    const STATUS_PREEMPTED = 'preempted'; // Status for bookings cancelled due to higher priority

    /**
     * Assigns priority level based on user type and booking type.
     * Higher integer means higher priority.
     *
     * @param User $user
     * @param string $bookingType
     * @return int
     */
    private function determinePriority(User $user, string $bookingType): int
    {
        // Adjust 'user_type' lookup based on your User model's actual column/relationship
        // For example, if you have a 'role_id' and a 'roles' table, you might use $user->role->name
        $userType = $user->user_type ?? $user->role?->name ?? 'student'; // Default to 'student' or 'other' if not found

        $priority = 0; // Base priority

        switch ($bookingType) {
            case 'university_activity':
                $priority = 4; // Highest for official university events
                break;
            case 'class':
                $priority = 3; // High for scheduled classes
                break;
            case 'staff_meeting':
                $priority = 2; // Medium for staff meetings
                break;
            case 'student_meeting':
                $priority = 1; // Lowest for student meetings
                break;
            default:
                $priority = 0; // Default for 'other' or undefined types
                break;
        }

        // Add bonus based on user type/role
        if (strtolower($userType) === 'admin') {
            $priority += 2; // Admins get a significant boost
        } elseif (strtolower($userType) === 'staff' || strtolower($userType) === 'lecturer') {
            $priority += 1; // Staff/Lecturers get a smaller boost
        }

        return $priority;
    }

    /**
     * Finds conflicting bookings for a given time slot and resource.
     *
     * @param int $resourceId
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param int|null $excludeBookingId
     * @return \Illuminate\Support\Collection<Booking>
     */
    public function findConflictingBookings(int $resourceId, Carbon $startTime, Carbon $endTime, ?int $excludeBookingId = null): \Illuminate\Support\Collection
    {
        $query = Booking::where('resource_id', $resourceId)
            // Consider approved, pending, and currently in-use bookings as conflicts
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_IN_USE])
            ->where(function ($q) use ($startTime, $endTime) {
                // Check for overlapping intervals: (start_A < end_B) AND (end_A > start_B)
                $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        // Eager load the user to access user_type and name for notifications and priority
        return $query->with('user:id,first_name,last_name,email,user_type,role_id')->get();
    }

    /**
     * Validates booking start and end times against business rules.
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @throws BookingException
     * @return void
     */
    private function validateBookingTimes(Carbon $startTime, Carbon $endTime): void
    {
        // Start time must be in the future (allowing for a small buffer to handle immediate requests)
        if ($startTime->lt(Carbon::now()->subMinutes(1))) {
            throw new BookingException('Booking start time must be in the future.');
        }

        // End time must be strictly after start time
        if ($endTime->lte($startTime)) {
            throw new BookingException('End time must be greater than start time.');
        }

        // Check duration constraints
        $durationInMinutes = $startTime->diffInMinutes($endTime);

        if ($durationInMinutes < self::MIN_DURATION_MINUTES) {
            throw new BookingException('Booking duration must be at least ' . self::MIN_DURATION_MINUTES . ' minutes.');
        }

        if ($durationInMinutes > (self::MAX_DURATION_HOURS * 60)) {
            throw new BookingException('Booking duration cannot exceed ' . self::MAX_DURATION_HOURS . ' hours.');
        }
    }

    /**
     * Validates if a user has exceeded their maximum active booking limit.
     *
     * @param User $user
     * @throws BookingException
     * @return void
     */
    private function validateUserBookingLimit(User $user): void
    {
        $activeBookingsCount = $user->bookings()
            ->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING, self::STATUS_IN_USE])
            ->where('end_time', '>', Carbon::now()) // Only count bookings that are still active or in the future
            ->count();

        if ($activeBookingsCount >= self::MAX_ACTIVE_BOOKINGS) {
            throw new BookingException('You have reached the maximum limit of ' . self::MAX_ACTIVE_BOOKINGS . ' active bookings.');
        }
    }

    /**
     * Validates resource existence and active status.
     *
     * @param int $resourceId
     * @return Resource
     * @throws BookingException
     */
    private function validateResource(int $resourceId): Resource
    {
        $resource = Resource::find($resourceId);

        if (!$resource) {
            throw new BookingException('Resource not found.');
        }

        if (!$resource->is_active) {
            throw new BookingException('The selected resource is currently not active.');
        }

        return $resource;
    }

    /**
     * Handles the creation and scheduling of a new booking request with priority logic.
     * This method contains the core "optimization" (priority-based preemption) algorithm.
     *
     * @param array $data Contains resource_id, start_time, end_time, purpose, booking_type
     * @param User $user The user making the booking
     * @return array Contains 'success', 'message', 'booking' (if successful), 'status_code'
     */
    public function createBooking(array $data, User $user): array
    {
        DB::beginTransaction();

        try {
            $resource = $this->validateResource($data['resource_id']);
            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            // Apply core business rule validations
            $this->validateBookingTimes($startTime, $endTime);
            $this->validateUserBookingLimit($user);

            $newBookingPriority = $this->determinePriority($user, $data['booking_type']);

            $conflictingBookings = $this->findConflictingBookings(
                $data['resource_id'],
                $startTime,
                $endTime
            );

            $preemptableConflicts = collect();
            $nonPreemptableConflicts = collect();

            foreach ($conflictingBookings as $conflict) {
                if ($newBookingPriority > $this->determinePriority($conflict->user, $conflict->booking_type)) {
                    $preemptableConflicts->push($conflict);
                } else {
                    $nonPreemptableConflicts->push($conflict);
                }
            }

            // Check if resource capacity is exceeded by non-preemptable bookings
            // If resource has capacity 1 and there's any non-preemptable conflict, it's a hard conflict.
            if ($resource->capacity == 1 && $nonPreemptableConflicts->isNotEmpty()) {
                throw new BookingException('The resource is not available due to a higher or equal priority booking.');
            }

            // For resources with capacity > 1, check if the combined count of new booking + non-preemptable conflicts exceeds capacity
            if ($resource->capacity > 1 && ($nonPreemptableConflicts->count() + 1 > $resource->capacity)) {
                throw new BookingException('Resource capacity is fully booked by higher or equal priority bookings.');
            }

            // All checks passed, proceed with booking.
            // First, preempt lower priority bookings
            foreach ($preemptableConflicts as $preemptedBooking) {
                $preemptedBooking->status = self::STATUS_PREEMPTED; // New status for clarity
                $preemptedBooking->cancellation_reason = 'Preempted by higher priority booking (Ref: ' . $this->generateBookingReference() . ')';
                $preemptedBooking->cancelled_at = Carbon::now();
                $preemptedBooking->save();

                // Notify the user whose booking was preempted
                if ($preemptedBooking->user) {
                    $preemptedBooking->user->notify(new BookingPreempted($preemptedBooking));
                }
            }

            // Create the new booking
            $booking = $user->bookings()->create([
                "booking_reference" => $this->generateBookingReference(),
                "resource_id" => $data['resource_id'],
                "start_time" => $startTime,
                "end_time" => $endTime,
                "status" => self::STATUS_APPROVED, // New high-priority booking is approved immediately
                "purpose" => $data['purpose'] ?? null,
                "booking_type" => $data['booking_type'],
                "priority" => $newBookingPriority, // Store the determined priority level
            ]);

            // Notify the user who made the new booking
            $user->notify(new BookingApproved($booking));

            DB::commit();

            return [
                'success' => true,
                'message' => 'Booking created successfully.',
                'booking' => $booking->load('resource', 'user'), // Eager load resource and user for response
                'status_code' => 201
            ];

        } catch (BookingException $e) {
            DB::rollBack();
            Log::warning('Booking validation failed: ' . $e->getMessage(), ['user_id' => $user->id ?? 'guest']);
            return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 400]; // Bad Request for validation errors
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'user_id' => $user->id ?? 'guest']);
            return ['success' => false, 'message' => 'An unexpected error occurred while creating the booking.', 'status_code' => 500];
        }
    }

    /**
     * Update an existing booking with priority scheduling considerations.
     *
     * @param Booking $booking The booking to update
     * @param array $data New data for the booking (start_time, end_time, purpose, booking_type)
     * @param User $user The user performing the update (for priority recalculation)
     * @return array Contains 'success', 'message', 'booking' (if successful), 'status_code'
     */
    public function updateBooking(Booking $booking, array $data, User $user): array
    {
        DB::beginTransaction();
        try {
            // Cannot modify bookings that have already started or are in the past
            if ($booking->start_time->lt(Carbon::now())) {
                throw new BookingException('Cannot modify bookings that have already started or are in the past.');
            }
            // Cannot modify cancelled or preempted bookings
            if (in_array($booking->status, [self::STATUS_CANCELLED, self::STATUS_PREEMPTED, self::STATUS_COMPLETED])) {
                throw new BookingException('Cannot modify a ' . $booking->status . ' booking.');
            }

            $startTime = isset($data['start_time']) ? Carbon::parse($data['start_time']) : $booking->start_time;
            $endTime = isset($data['end_time']) ? Carbon::parse($data['end_time']) : $booking->end_time;
            $resource = $this->validateResource($booking->resource_id); // Re-validate resource for safety
            $bookingType = $data['booking_type'] ?? $booking->booking_type;

            // Apply core business rule validations if times are being changed
            if (isset($data['start_time']) || isset($data['end_time'])) {
                $this->validateBookingTimes($startTime, $endTime);
            }

            // Determine new priority level for the updated booking
            $newPriorityLevel = $this->determinePriority($user, $bookingType);

            // Find conflicts, EXCLUDING the current booking being updated from the conflict check
            $conflictingBookings = $this->findConflictingBookings(
                $booking->resource_id,
                $startTime,
                $endTime,
                $booking->id // Exclude the current booking's ID
            );

            $preemptableConflicts = collect();
            $nonPreemptableConflicts = collect();

            foreach ($conflictingBookings as $conflict) {
                 // A conflict is preemptable if the UPDATED booking's priority is strictly higher than the existing conflict's priority
                if ($newPriorityLevel > $this->determinePriority($conflict->user, $conflict->booking_type)) {
                    $preemptableConflicts->push($conflict);
                } else {
                    $nonPreemptableConflicts->push($conflict);
                }
            }

            // Check if resource capacity is exceeded by non-preemptable bookings (after considering preemption)
            if ($resource->capacity == 1 && $nonPreemptableConflicts->isNotEmpty()) {
                throw new BookingException('Cannot update: a higher or equal priority booking already exists for this time slot.');
            }
            if ($resource->capacity > 1 && ($nonPreemptableConflicts->count() + 1 > $resource->capacity)) { // +1 for the updated booking itself
                throw new BookingException('Cannot update: resource capacity is fully booked by higher or equal priority bookings.');
            }

            // Proceed with update
            // Preempt lower priority bookings
            foreach ($preemptableConflicts as $preemptedBooking) {
                $preemptedBooking->status = self::STATUS_PREEMPTED;
                $preemptedBooking->cancellation_reason = 'Preempted by updated higher priority booking (Ref: ' . $booking->booking_reference . ')';
                $preemptedBooking->cancelled_at = Carbon::now();
                $preemptedBooking->save();
                if ($preemptedBooking->user) {
                    $preemptedBooking->user->notify(new BookingPreempted($preemptedBooking));
                }
            }

            // Update the booking details
            $booking->fill($data); // Fill with validated data
            $booking->start_time = $startTime; // Ensure carbon instances are used
            $booking->end_time = $endTime;
            $booking->priority_level = $newPriorityLevel; // Update priority level
            $booking->booking_type = $bookingType;
            $booking->status = self::STATUS_APPROVED; // Re-approve if it successfully fits/preempted
            $booking->save();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Booking updated successfully and conflicts resolved by priority.',
                'booking' => $booking->fresh()->load('resource', 'user'),
                'status_code' => 200
            ];

        } catch (BookingException $e) {
            DB::rollBack();
            Log::warning('Booking update validation failed: ' . $e->getMessage(), ['user_id' => $user->id ?? 'guest']);
            return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 400];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'user_id' => $user->id ?? 'guest']);
            return ['success' => false, 'message' => 'An unexpected error occurred while updating the booking.', 'status_code' => 500];
        }
    }

    /**
     * Cancel a booking.
     *
     * @param Booking $booking
     * @return array
     */
    public function cancelBooking(Booking $booking): array
    {
        DB::beginTransaction();
        try {
            if (in_array($booking->status, [self::STATUS_CANCELLED, self::STATUS_PREEMPTED, self::STATUS_COMPLETED])) {
                throw new BookingException('Cannot cancel a booking that is already ' . $booking->status . '.');
            }
            // Allow cancellation of future or ongoing bookings only
            if ($booking->end_time->lt(Carbon::now())) {
                 throw new BookingException('Cannot cancel bookings that have already completed.');
            }

            $booking->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => 'User cancelled booking.' // Default reason, can be extended via request
            ]);

            // Notify user of cancellation
            if ($booking->user) {
                $booking->user->notify(new BookingRejected($booking)); // Reusing Rejected notification for cancellation
            }

            DB::commit();
            return [
                'success' => true,
                'message' => 'Booking cancelled successfully.',
                'booking' => $booking->fresh(),
                'status_code' => 200
            ];
        } catch (BookingException $e) {
            DB::rollBack();
            Log::warning('Booking cancellation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 400];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking cancellation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'message' => 'An unexpected error occurred while cancelling the booking.', 'status_code' => 500];
        }
    }

    /**
     * Cancel multiple bookings for a user.
     *
     * @param array $bookingIds
     * @param int $userId
     * @param string|null $reason
     * @return array
     */
    public function cancelMultipleBookings(array $bookingIds, int $userId, ?string $reason = null): array
    {
        DB::beginTransaction();
        $cancelledCount = 0;
        $errors = [];

        foreach ($bookingIds as $bookingId) {
            try {
                $booking = Booking::where('id', $bookingId)->where('user_id', $userId)->first();
                if (!$booking) {
                    $errors[] = "Booking #{$bookingId} not found or does not belong to user.";
                    continue;
                }
                // Use the single cancellation method to ensure all rules and notifications apply
                $result = $this->cancelBooking($booking);
                if ($result['success']) {
                    $cancelledCount++;
                } else {
                    $errors[] = "Booking #{$bookingId} cannot be cancelled: " . $result['message'];
                }
            } catch (\Exception $e) {
                $errors[] = "Booking #{$bookingId} encountered an error: " . $e->getMessage();
                Log::error('Error cancelling multiple bookings: ' . $e->getMessage(), ['booking_id' => $bookingId, 'user_id' => $userId, 'trace' => $e->getTraceAsString()]);
            }
        }
        DB::commit(); // Commit after loop if individual operations were successful. If any failed, they're rolled back by their internal `cancelBooking` call.

        return [
            'cancelled_count' => $cancelledCount,
            'total_requested' => count($bookingIds),
            'errors' => $errors
        ];
    }

    /**
     * Get cancellation statistics for a user.
     *
     * @param int $userId
     * @return object
     */
    public function getCancellationStats(int $userId): object
    {
        $stats = Booking::where('user_id', $userId)
            ->selectRaw('
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_bookings,
                SUM(CASE WHEN status = ? AND cancelled_at >= ? THEN 1 ELSE 0 END) as recent_cancellations
            ', [
                self::STATUS_CANCELLED,
                self::STATUS_CANCELLED,
                Carbon::now()->subDays(30)
            ])
            ->first();

        return $stats;
    }

    private function generateBookingReference(): string
    {
         do {
             $reference = 'MZUNI-RBA-' . now()->format('dmHi') . '-' . strtoupper(Str::random(6));
         } while (Booking::where('booking_reference', $reference)->exists());

         return $reference;
    }
    /**
     * Check resource availability based on conflicts (without creating a booking).
     *
     * @param int $resourceId
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param int|null $excludeBookingId
     * @return array
     */
    public function checkAvailabilityStatus(int $resourceId, Carbon $startTime, Carbon $endTime, ?int $excludeBookingId = null): array
    {
        try {
            $resource = $this->validateResource($resourceId);

            // Basic time validation for the check
            $this->validateBookingTimes($startTime, $endTime);

            $conflictingBookings = $this->findConflictingBookings(
                $resourceId,
                $startTime,
                $endTime,
                $excludeBookingId
            );

            $conflictCount = $conflictingBookings->count();

            if ($resource->capacity == 1 && $conflictCount > 0) {
                return [
                    'available' => false,
                    'hasConflict' => true,
                    'message' => 'Resource is already fully booked during this time.',
                    'conflicts' => $conflictingBookings->map(function ($booking) {
                        return [
                            'id' => $booking->id,
                            'start_time' => $booking->start_time->format('Y-m-d H:i'),
                            'end_time' => $booking->end_time->format('Y-m-d H:i'),
                            'user' => $booking->user ? ($booking->user->first_name . ' ' . $booking->user->last_name) : 'Unknown',
                            'priority_level' => $booking->priority_level ?? null,
                            'status' => $booking->status, // Include status
                        ];
                    })
                ];
            }

            if ($resource->capacity > 1 && $conflictCount >= $resource->capacity) {
                return [
                    'available' => false,
                    'hasConflict' => true,
                    'message' => sprintf(
                        'Resource capacity (%d) is fully booked for the selected time period.',
                        $resource->capacity
                    ),
                    'conflicts' => $conflictingBookings->map(function ($booking) {
                        return [
                            'id' => $booking->id,
                            'start_time' => $booking->start_time->format('Y-m-d H:i'),
                            'end_time' => $booking->end_time->format('Y-m-d H:i'),
                            'user' => $booking->user ? ($booking->user->first_name . ' ' . $booking->user->last_name) : 'Unknown',
                            'priority_level' => $booking->priority_level ?? null,
                            'status' => $booking->status, // Include status
                        ];
                    })
                ];
            }

            // If there are conflicts but capacity allows, or capacity is multiple and conflicts < capacity, it's available.
            // This method does not consider priority for "availability", only for actual booking.
            // The priority logic is handled in `createBooking` and `updateBooking`.
            return [
                'available' => true,
                'hasConflict' => false,
                'message' => 'Time slot is available.',
                'conflicts' => $conflictingBookings->map(function ($booking) { // Still return conflicts, even if available
                    return [
                        'id' => $booking->id,
                        'start_time' => $booking->start_time->format('Y-m-d H:i'),
                        'end_time' => $booking->end_time->format('Y-m-d H:i'),
                        'user' => $booking->user ? ($booking->user->first_name . ' ' . $booking->user->last_name) : 'Unknown',
                        'priority_level' => $booking->priority_level ?? null,
                        'status' => $booking->status,
                    ];
                })
            ];

        } catch (BookingException $e) {
            Log::warning('Availability check failed: ' . $e->getMessage());
            return ['available' => false, 'hasConflict' => true, 'message' => $e->getMessage(), 'conflicts' => []];
        } catch (\Exception $e) {
            Log::error('Availability check failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['available' => false, 'hasConflict' => true, 'message' => 'An unexpected error occurred during availability check.', 'conflicts' => []];
        }
    }
}
