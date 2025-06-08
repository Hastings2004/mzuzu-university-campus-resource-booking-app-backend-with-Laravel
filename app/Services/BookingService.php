<?php

namespace App\Serveces;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    /**
     * Create a new class instance.
     */
    const MIN_DURATION_MINUTES = 30;
    const MAX_DURATION_HOURS = 8;
    const MAX_ACTIVE_BOOKINGS = 5;

    /**
     * Create a new booking
     */
    public function createBooking(User $user, array $data): Booking
    {
        $startTime = Carbon::parse($data['start_time']);
        $endTime = Carbon::parse($data['end_time']);
        $resourceId = $data['resource_id'];

        // Validate booking rules
        $this->validateBookingTimes($startTime, $endTime);
        $this->validateUserBookingLimit($user);
        $resource = $this->validateResourceAvailability($resourceId, $startTime, $endTime);

        // Create booking
        return $user->bookings()->create([
            'booking_reference' => $this->generateBookingReference(),
            'resource_id' => $resourceId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'approved',
            'purpose' => $data['purpose'] ?? null,
        ]);
    }

    /**
     * Update an existing booking
     */
    public function updateBooking(Booking $booking, array $data): Booking
    {
        // Only allow updates to future bookings
        if ($booking->start_time <= Carbon::now()) {
            throw new BookingException('Cannot modify bookings that have already started.');
        }

        $startTime = isset($data['start_time']) ? Carbon::parse($data['start_time']) : $booking->start_time;
        $endTime = isset($data['end_time']) ? Carbon::parse($data['end_time']) : $booking->end_time;

        // If times are being changed, validate them
        if (isset($data['start_time']) || isset($data['end_time'])) {
            $this->validateBookingTimes($startTime, $endTime);
            $this->validateResourceAvailability($booking->resource_id, $startTime, $endTime, $booking->id);
        }

        $booking->update([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'purpose' => $data['purpose'] ?? $booking->purpose,
        ]);

        return $booking->fresh();
    }

    /**
     * Cancel a booking
     */
    public function cancelBooking(Booking $booking): void
    {
        // Only allow cancellation of future bookings
        if ($booking->start_time <= Carbon::now()) {
            throw new BookingException('Cannot cancel bookings that have already started.');
        }

        $booking->update(['status' => 'cancelled']);
    }

    /**
     * Validate booking start and end times
     */
    private function validateBookingTimes(Carbon $startTime, Carbon $endTime): void
    {
        // Start time must be in the future
        if ($startTime <= Carbon::now()) {
            throw new BookingException('Booking start time must be in the future.');
        }

        // End time must be after start time
        if ($endTime <= $startTime) {
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
     */
    private function validateUserBookingLimit(User $user): void
    {
        $activeBookingsCount = $user->bookings()
            ->whereIn('status', ['approved', 'pending'])
            ->where('end_time', '>', Carbon::now())
            ->count();

        if ($activeBookingsCount >= self::MAX_ACTIVE_BOOKINGS) {
            throw new BookingException('You have reached the maximum limit of ' . self::MAX_ACTIVE_BOOKINGS . ' active bookings.');
        }
    }

    /**
     * Validate resource availability
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

        // Check for overlapping bookings
        $query = Booking::where('resource_id', $resourceId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime, $endTime) {
                    // New booking starts during existing booking
                    $q->where('start_time', '<=', $startTime)
                      ->where('end_time', '>', $startTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New booking ends during existing booking
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>=', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New booking completely encompasses existing booking
                    $q->where('start_time', '>=', $startTime)
                      ->where('end_time', '<=', $endTime);
                });
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        $overlappingBookings = $query->count();

        if ($overlappingBookings > 0) {
            throw new BookingException('The resource is not available during the requested time slot.');
        }

        return $resource;
    }

    /**
     * Generate a unique booking reference
     */
    private function generateBookingReference(): string
    {
        do {
            $reference = 'BK-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (Booking::where('booking_reference', $reference)->exists());

        return $reference;
    }

    /**
     * Cancel multiple bookings for a user
     */
    public function cancelMultipleBookings(array $bookingIds, $userId, $reason = null)
    {
        return DB::transaction(function () use ($bookingIds, $userId, $reason) {
            $bookings = Booking::whereIn('id', $bookingIds)
                ->where('user_id', $userId)
                ->active()
                ->notExpired()
                ->get();

            $cancelledCount = 0;
            $errors = [];

            foreach ($bookings as $booking) {
                if ($booking->canBeCancelled()) {
                    $booking->update([
                        'status' => Booking::STATUS_CANCELLED,
                        'cancelled_at' => Carbon::now(),
                        'cancellation_reason' => $reason
                    ]);
                    $cancelledCount++;
                } else {
                    $errors[] = "Booking #{$booking->id} cannot be cancelled";
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
     */
    public function getCancellationStats($userId)
    {
        $stats = Booking::where('user_id', $userId)
            ->selectRaw('
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_bookings,
                SUM(CASE WHEN status = ? AND cancelled_at >= ? THEN 1 ELSE 0 END) as recent_cancellations
            ', [
                Booking::STATUS_CANCELLED,
                Booking::STATUS_CANCELLED,
                Carbon::now()->subDays(30)
            ])
            ->first();

        return $stats;
    }
}
