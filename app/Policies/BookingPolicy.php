<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Access\HandlesAuthorization;

class BookingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the booking.
     */
    public function view(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create bookings.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create bookings
    }

    /**
     * Determine whether the user can update the booking.
     */
    public function update(User $user, Booking $booking): bool
    {
        // Only the booking owner can update, and only if it hasn't started
        return $user->id === $booking->user_id && 
               $booking->start_time > now() &&
               $booking->status !== 'cancelled';
    }

    /**
     * Determine whether the user can delete the booking.
     */
    public function delete(User $user, Booking $booking): bool
    {
        // Only the booking owner can cancel, and only if it hasn't started
        return $user->id === $booking->user_id && 
               $booking->start_time > now() &&
               $booking->status !== 'cancelled';
    }
}
