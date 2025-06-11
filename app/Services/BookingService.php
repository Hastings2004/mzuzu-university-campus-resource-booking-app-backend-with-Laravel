<?php

namespace App\Services; // Corrected namespace from App\Serveces

use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Ensure Log facade is imported
use Illuminate\Support\Str; // Ensure Str facade is imported
use App\Notifications\BookingApproved; // Assuming these notifications exist
use App\Notifications\BookingRejected;
use App\Notifications\BookingPreempted;
use App\Exceptions\BookingException; // Assuming this custom exception exists

// BookingException is assumed to exist in App\Exceptions\BookingException

class BookingService
{
    /**
     * Create a new class instance.
     */
    const MIN_DURATION_MINUTES = 30;
    const MAX_DURATION_HOURS = 8;
    const MAX_ACTIVE_BOOKINGS = 5;

    // Define booking statuses consistently
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_IN_USE = 'in_use'; // Added based on previous discussion
    const STATUS_PREEMPTED = 'preempted'; // New status for preemption

    /**
     * Assigns priority level based on user type and booking type.
     *
     * @param User $user
     * @param string $bookingType
     * @return int
     */
    private function determinePriority(User $user, string $bookingType): int
    {
        // Example: Assuming user_type column exists (e.g., from a 'roles' relationship or direct column)
        // You might map user roles to types if 'user_type' isn't a direct column.
        $userType = $user->user_type ?? $user->role->name ?? 'other'; // Adjust based on your User model structure

        switch ($bookingType) {
            case 'university_activity':
                return 4; // Highest priority
            case 'class':
                // Only staff (lecturers) can typically book classes
                return (strtolower($userType) === 'staff' || strtolower($userType) === 'lecturer') ? 3 : 0;
            case 'staff_meeting':
                return (strtolower($userType) === 'staff') ? 2 : 0;
            case 'student_meeting':
                return (strtolower($userType) === 'student') ? 1 : 0;
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
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_IN_USE]) // Only consider active/in-use bookings for conflicts
            ->where(function ($q) use ($startTime, $endTime) {
                // Check for overlapping intervals
                // (start_time < new_end_time) AND (end_time > new_start_time)
                $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        // Eager load the user to access user_type and name for notifications
        return $query->with('user:id,first_name,last_name,email,user_type,role_id')->get(); // Added role_id for flexibility
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

        try {
            $resource = Resource::find($data['resource_id']);
            if (!$resource) {
                throw new BookingException('Resource not found.');
            }

            if (!$resource->is_active) {
                throw new BookingException('The selected resource is currently not active.');
            }

            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            // --- Re-integrating core validation logic ---
            $this->validateBookingTimes($startTime, $endTime);
            $this->validateUserBookingLimit($user);
            // --- End re-integrated validation ---

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

            // Count non-preemptable conflicts (those with equal or higher priority)
            $nonPreemptableConflicts = $conflictingBookings->filter(function ($conflict) use ($newBookingPriority) {
                return $newBookingPriority <= $conflict->priority_level;
            });

            // Check if resource capacity is exceeded by non-preemptable bookings
            if ($resource->capacity == 1) {
                if ($nonPreemptableConflicts->isNotEmpty()) {
                    throw new BookingException('The resource is not available during the requested time slot due to a higher or equal priority booking.');
                }
            } elseif ($resource->capacity > 1) {
                if (($nonPreemptableConflicts->count() + 1) > $resource->capacity) { // +1 for the new booking itself
                    throw new BookingException('Resource capacity is fully booked for the selected time period by higher or equal priority bookings.');
                }
            }


            // All checks passed, we can proceed.
            // First, preempt lower priority bookings
            foreach ($preemptableConflicts as $preemptedBooking) {
                $preemptedBooking->status = self::STATUS_PREEMPTED; // A new status for clarity
                $preemptedBooking->cancellation_reason = 'Preempted by higher priority booking (' . ($data['booking_reference'] ?? 'N/A') . ')'; // Use provided ref or N/A
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
                "purpose" => $data['purpose'] ?? null, // Ensure purpose is nullable if not always provided
                "booking_type" => $data['booking_type'],
                "priority_level" => $newBookingPriority, // Ensure your booking model has this field
            ]);

            // Notify the user who made the new booking
            $user->notify(new BookingApproved($booking));

            DB::commit();

            return [
                'success' => true,
                'message' => 'Booking created successfully.',
                'booking' => $booking->load('resource'), // Eager load resource for response
                'status_code' => 201
            ];

        } catch (BookingException $e) {
            DB::rollBack();
            Log::warning('Booking validation failed: ' . $e->getMessage()); // Log validation failures as warning
            return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 400]; // Bad Request for validation errors
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'message' => 'An unexpected error occurred while creating the booking.', 'status_code' => 500];
        }
    }

    /**
     * Update an existing booking
     *
     * @param Booking $booking
     * @param array $data
     * @return array // Changed return type to array for consistency with createBooking
     */
    public function updateBooking(Booking $booking, array $data): array
    {
        DB::beginTransaction();
        try {
            // Only allow updates to future bookings (start time must be > now)
            if ($booking->start_time->lt(Carbon::now())) {
                throw new BookingException('Cannot modify bookings that have already started or are in the past.');
            }
            // Cannot modify cancelled bookings
            if ($booking->status === self::STATUS_CANCELLED) {
                throw new BookingException('Cannot modify a cancelled booking.');
            }

            $startTime = isset($data['start_time']) ? Carbon::parse($data['start_time']) : $booking->start_time;
            $endTime = isset($data['end_time']) ? Carbon::parse($data['end_time']) : $booking->end_time;
            $resourceId = $booking->resource_id; // Resource ID usually doesn't change on update

            // If times are being changed, validate them
            if (isset($data['start_time']) || isset($data['end_time'])) {
                $this->validateBookingTimes($startTime, $endTime);
                // Validate resource availability, excluding the current booking being updated
                $this->validateResourceAvailability($resourceId, $startTime, $endTime, $booking->id);
            }

            $booking->update([
                'start_time' => $startTime,
                'end_time' => $endTime,
                'purpose' => $data['purpose'] ?? $booking->purpose,
                // 'status' => $data['status'] ?? $booking->status, // If status can be changed via update
            ]);

            DB::commit();
            return [
                'success' => true,
                'message' => 'Booking updated successfully.',
                'booking' => $booking->fresh()->load('resource'),
                'status_code' => 200
            ];
        } catch (BookingException $e) {
            DB::rollBack();
            Log::warning('Booking update validation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 400];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'message' => 'An unexpected error occurred while updating the booking.', 'status_code' => 500];
        }
    }

    /**
     * Cancel a booking
     *
     * @param Booking $booking
     * @return array // Changed return type to array for consistency
     */
    public function cancelBooking(Booking $booking): array
    {
        DB::beginTransaction();
        try {
            // Only allow cancellation of future or ongoing bookings (if not already completed/cancelled/preempted)
            if ($booking->status === self::STATUS_CANCELLED || $booking->status === self::STATUS_COMPLETED || $booking->status === self::STATUS_PREEMPTED) {
                throw new BookingException('Cannot cancel a booking that is already ' . $booking->status . '.');
            }
            if ($booking->end_time->lt(Carbon::now())) { // Check if booking has already ended
                 throw new BookingException('Cannot cancel bookings that have already completed.');
            }

            $booking->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now()
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
     * Validate booking start and end times
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @throws BookingException
     * @return void
     */
    private function validateBookingTimes(Carbon $startTime, Carbon $endTime): void
    {
        // Start time must be in the future (allowing current minute for immediate bookings if needed)
        // Using gt() for strictly greater than current minute, or gte() if current minute is allowed.
        // For simplicity, let's stick to strictly future if a new booking.
        if ($startTime->lt(Carbon::now()->subMinutes(1))) { // A small buffer to account for request time
            throw new BookingException('Booking start time must be in the future.');
        }

        // End time must be after start time
        if ($endTime->lte($startTime)) {
            throw new BookingException('End time must be greater than start time.');
        }

        // Check duration constraints
        $durationInMinutes = $endTime->diffInMinutes($startTime);

        if ($durationInMinutes < self::MIN_DURATION_MINUTES) {
            throw new BookingException('Booking duration must be at least ' . self::MIN_DURATION_MINUTES . ' minutes.');
        }

        if ($durationInMinutes > (self::MAX_DURATION_HOURS * 60)) {
            throw new BookingException('Booking duration cannot exceed ' . self::MAX_DURATION_HOURS . ' hours.');
        }
    }

    /**
     * Validate user hasn't exceeded booking limit
     *
     * @param User $user
     * @throws BookingException
     * @return void
     */
    private function validateUserBookingLimit(User $user): void
    {
        $activeBookingsCount = $user->bookings()
            ->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING, self::STATUS_IN_USE])
            ->where('end_time', '>', Carbon::now())
            ->count();

        if ($activeBookingsCount >= self::MAX_ACTIVE_BOOKINGS) {
            throw new BookingException('You have reached the maximum limit of ' . self::MAX_ACTIVE_BOOKINGS . ' active bookings.');
        }
    }

    /**
     * Validate resource availability and return resource object.
     * This method primarily checks if the resource exists and is active.
     * Overlapping checks are now handled more granularly by findConflictingBookings and priority logic.
     *
     * @param int $resourceId
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param int|null $excludeBookingId
     * @return Resource
     * @throws BookingException
     */
    private function validateResourceAvailability(int $resourceId, Carbon $startTime, Carbon $endTime, ?int $excludeBookingId = null): Resource
    {
        $resource = Resource::find($resourceId);

        if (!$resource) {
            throw new BookingException('Resource not found.');
        }

        if (!$resource->is_active) {
            throw new BookingException('The selected resource is currently not active.');
        }

        // The more complex overlapping logic, considering capacity and preemption,
        // is primarily handled in `createBooking` by calling `findConflictingBookings`
        // and then applying priority rules. This `validateResourceAvailability` focuses on
        // the resource's existence and active status.
        // For `updateBooking`, it's called with `excludeBookingId` and relies on `findConflictingBookings`
        // to return the relevant conflicts (if any after excluding itself).
        // It's crucial that `updateBooking` (and any other method using this) handles the outcome
        // of `findConflictingBookings` appropriately.

        // Re-check simple overlap if this is for a context without full priority logic (e.g. updating a booking without changing priority)
        // If findConflictingBookings is used directly and its result implies a hard conflict, an exception should be thrown.
        $conflictingBookings = $this->findConflictingBookings($resourceId, $startTime, $endTime, $excludeBookingId);
        if ($conflictingBookings->count() > 0 && $resource->capacity === 1) { // Basic check for single capacity resource
            throw new BookingException('The resource is not available during the requested time slot (simple conflict).');
        }
        // More complex capacity checks would occur at the higher level (e.g., in createBooking/updateBooking)
        // where priority is factored in.

        return $resource;
    }


    /**
     * Generate a unique booking reference.
     *
     * @return string
     */
    private function generateBookingReference(): string
    {
        do {
            $reference = 'MZUNI-RBA-' . now()->format('dmHi') . '-' . strtoupper(Str::random(4));
        } while (Booking::where('booking_reference', $reference)->exists());

        return $reference;
    }

    /**
     * Cancel multiple bookings for a user
     *
     * @param array $bookingIds
     * @param int $userId
     * @param string|null $reason
     * @return array
     */
    public function cancelMultipleBookings(array $bookingIds, int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($bookingIds, $userId, $reason) {
            $bookings = Booking::whereIn('id', $bookingIds)
                ->where('user_id', $userId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_IN_USE]) // Only active/future bookings
                ->where('end_time', '>', Carbon::now()) // Not yet completed
                ->get();

            $cancelledCount = 0;
            $errors = [];

            foreach ($bookings as $booking) {
                // You might add a 'canBeCancelled()' method to your Booking model for more complex rules
                // For now, based on the query above, these should be cancellable if found.
                try {
                    $this->cancelBooking($booking); // Use the single cancellation logic
                    $cancelledCount++;
                } catch (BookingException $e) {
                    $errors[] = "Booking #{$booking->id} cannot be cancelled: " . $e->getMessage();
                }
            }

            return [
                'cancelled_count' => $cancelledCount,
                'total_requested' => count($bookingIds),
                'errors' => $errors
            ];
        });
    }

    /**
     * Get cancellation statistics for a user
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
}
