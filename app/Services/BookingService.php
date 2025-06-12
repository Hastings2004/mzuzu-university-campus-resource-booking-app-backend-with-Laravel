<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Notifications\BookingApproved;
use App\Notifications\BookingRejected;
use App\Notifications\BookingPreempted;
use Illuminate\Support\Str;
use App\Exceptions\BookingException; 
use Illuminate\Http\Request;

class BookingService
{
    // Define constants for booking rules and statuses
    const MIN_DURATION_MINUTES = 30;
    const MAX_DURATION_HOURS = 8;
    const MAX_ACTIVE_BOOKINGS = 5; 

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_IN_USE = 'in_use'; 
    const STATUS_PREEMPTED = 'preempted'; 
    const STATUS_EXPIRED = 'expired';

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
        // Determine user type/role
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

        //try {
            $resource = $this->validateResource($data['resource_id']);
            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            // Apply core business rule validations
            $this->validateBookingTimes($startTime, $endTime);
            $this->validateUserBookingLimit($user);

            $newBookingPriority = $this->determinePriority($user, $data['booking_type']);

            // Check if the user has exceeded their booking limit
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

        // } catch (BookingException $e) {
        //     DB::rollBack();
        //     Log::warning('Booking validation failed: ' . $e->getMessage(), ['user_id' => $user->id ?? 'guest']);
        //     return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 400]; // Bad Request for validation errors
        // } catch (\Exception $e) {
        //     DB::rollBack();
        //     Log::error('Booking creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'user_id' => $user->id ?? 'guest']);
        //     return ['success' => false, 'message' => 'An unexpected error occurred while creating the booking.', 'status_code' => 500];
        // }
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

    /**
     * Marks bookings as 'expired' if their end time has passed.
     * This function should ideally be run as a scheduled task (e.g., daily or hourly).
     *
     * @return int The number of bookings that were marked as expired.
     */
    public function markExpiredBookings(): int
    {
        $now = Carbon::now();

        // Query for bookings that have ended and are not already in a final state
        // We exclude cancelled, preempted, completed, and already expired bookings
        $updatedCount = Booking::where('end_time', '<', $now)
            ->whereNotIn('status', [
                self::STATUS_CANCELLED,
                self::STATUS_PREEMPTED,
                self::STATUS_COMPLETED,
                self::STATUS_EXPIRED // Exclude already expired bookings
            ])
            ->update(['status' => self::STATUS_EXPIRED]);

        if ($updatedCount > 0) {
            Log::info("{$updatedCount} bookings marked as expired.");
        }

        return $updatedCount;
    }

    /**
     * Get user's bookings with pagination and filtering
     */
    public function getUserBookings(Request $request)
    {
        try {
            $perPage = min($request->get('per_page', 10), 50); // Limit per page
            $status = $request->get('status');
            $upcoming = $request->get('upcoming', false);

            $query = $request-> user()->bookings()
                ->with(['resource:id,name,location'])
                ->select(['id', 'resource_id', 'start_time', 'end_time', 'status', 'purpose', 'created_at']);

            if ($status) {
                $query->where('status', $status);
            }

            if ($upcoming) {
                $query->where('start_time', '>', now());
            }

            $bookings = $query->orderBy('start_time', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'bookings' => $bookings->items(),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user bookings', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings'
            ], 500);
        }
    }


    
}
