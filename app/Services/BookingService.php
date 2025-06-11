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

namespace App\Services; // Make sure this namespace is correct, typically App\Services

use App\Models\Booking;
use App\Models\Resource;
use App\Models\User; // Assuming User model is in App\Models
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\BookingApproved;
use App\Notifications\BookingRejected;
use App\Notifications\BookingPreempted;
use Illuminate\Support\Str; // For generateBookingReference if moved here

class BookingService
{
    /**
     * Assigns priority level based on user type and booking type.
     *
     * @param User $user
     * @param string $bookingType
     * @return int
     */
    private function determinePriority(User $user, string $bookingType): int
    {
        switch ($bookingType) {
            case 'university_activity':
                return 4; // Highest priority
            case 'class':
                // Only staff (lecturers) can typically book classes
                return ($user->user_type === 'staff') ? 3 : 0;
            case 'staff_meeting':
                return ($user->user_type === 'staff') ? 2 : 0;
            case 'student_meeting':
                return ($user->user_type === 'student') ? 1 : 0;
            default:
                return 0; // Default for 'other' or mismatch
        }
    }

    /**
     * Finds conflicting bookings for a given time slot and resource.
     *
     * @param int $resourceId
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param int|null $excludeBookingId
     * @return \Illuminate\Support\Collection
     */
    public function findConflictingBookings(int $resourceId, Carbon $startTime, Carbon $endTime, ?int $excludeBookingId = null): \Illuminate\Support\Collection
    {
        $query = Booking::where('resource_id', $resourceId)
            ->whereIn('status', ['pending', 'approved']) // Only consider active bookings for conflicts
            ->where(function ($q) use ($startTime, $endTime) {
                // Check for overlapping intervals
                $q->where('start_time', '<', $endTime)
                  ->where('end_time', '>', $startTime);
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        // Eager load the user to access user_type and name for notifications
        return $query->with('user:id,first_name,last_name,email,user_type')->get();
    }

    /**
     * Handles the creation and scheduling of a new booking request with priority logic.
     *
     * @param array $data
     * @param User $user
     * @return array
     */
    public function createBooking(array $data, User $user): array
    {
        DB::beginTransaction();

        //try {
            $resource = Resource::find($data['resource_id']);
            if (!$resource) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Resource not found.', 'status_code' => 404];
            }

            if (!$resource->is_active) {
                DB::rollBack();
                return ['success' => false, 'message' => 'The selected resource is currently not active.', 'status_code' => 422];
            }

            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            // Determine priority for the new booking
            $newBookingPriority = $this->determinePriority($user, $data['booking_type']);

            $conflictingBookings = $this->findConflictingBookings(
                $data['resource_id'],
                $startTime,
                $endTime
            );

            // Filter out bookings that cannot be preempted
            $preemptableConflicts = $conflictingBookings->filter(function ($conflict) use ($newBookingPriority) {
                // A booking can be preempted if the new booking's priority is strictly higher
                return $newBookingPriority > $conflict->priority_level;
            });

            // Count non-preemptable conflicts
            $nonPreemptableConflicts = $conflictingBookings->filter(function ($conflict) use ($newBookingPriority) {
                // A booking is not preemptable if its priority is equal to or higher than the new booking
                return $newBookingPriority <= $conflict->priority_level;
            });

            // Check if resource capacity is exceeded by non-preemptable bookings
            // For capacity 1 resources, if there's any non-preemptable conflict, it's a hard conflict.
            if ($resource->capacity == 1 && $nonPreemptableConflicts->isNotEmpty()) {
                DB::rollBack();
                return ['success' => false, 'message' => 'The resource is not available during the requested time slot due to a higher priority booking.', 'status_code' => 409];
            }

            // For resources with capacity > 1, check if the combined count of new booking + non-preemptable conflicts exceeds capacity
            if ($resource->capacity > 1 && ($nonPreemptableConflicts->count() + 1 > $resource->capacity)) {
                 DB::rollBack();
                return ['success' => false, 'message' => 'Resource capacity is fully booked for the selected time period by higher or equal priority bookings.', 'status_code' => 409];
            }


            // All checks passed, we can proceed.
            // First, preempt lower priority bookings
            foreach ($preemptableConflicts as $preemptedBooking) {
                $preemptedBooking->status = 'preempted'; // A new status for clarity
                $preemptedBooking->cancellation_reason = 'Preempted by higher priority booking (Ref: ' . $data['booking_reference'] . ')';
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
                "status" => "approved", // New high-priority booking is approved immediately
                "purpose" => $data['purpose'],
                "booking_type" => $data['booking_type'],
                "priority" => $newBookingPriority,
            ]);

            // Notify the user who made the new booking
            $user->notify(new BookingApproved($booking));

            DB::commit();

            return [
                'success' => true,
                'message' => 'Booking created successfully.',
                'booking' => $booking->load('resource'),
                'status_code' => 201
            ];

        // } catch (\Exception $e) {
        //     DB::rollBack();
        //     Log::error('Booking creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        //      return ['success' => false, 'message' => 'An error occurred while creating the booking.', 'status_code' => 500];
        // }
    }

    /**
     * Generate a unique booking reference.
     * Moved from controller to service for consistency.
     */
    private function generateBookingReference(): string
    {
        do {
            $reference = 'MZUNI-RBA-' . now()->format('dmHi') . '-' . strtoupper(Str::random(4));
        } while (Booking::where('booking_reference', $reference)->exists());

        return $reference;
    }
}