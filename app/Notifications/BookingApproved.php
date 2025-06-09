<?php

// app/Notifications/BookingApproved.php
namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingApproved extends Notification implements ShouldQueue
{
    use Queueable;
    protected $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Your Resource Booking is Approved!')
                    ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
                    ->line('Great news! Your booking for **' . $this->booking->resource->name . '** has been successfully approved.')
                    ->line('**Reference:** ' . $this->booking->booking_reference)
                    ->line('**Time:** ' . $this->booking->start_time->format('Y-m-d H:i') . ' - ' . $this->booking->end_time->format('Y-m-d H:i'))
                    ->line('**Purpose:** ' . $this->booking->purpose)
                    ->action('View Your Booking', url('/my-bookings/' . $this->booking->id))
                    ->line('Thank you for using our campus resource booking system!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'resource_name' => $this->booking->resource->name,
            'start_time' => $this->booking->start_time->toDateTimeString(),
            'end_time' => $this->booking->end_time->toDateTimeString(),
            'status' => 'approved',
            'message' => 'Your booking has been approved.',
        ];
    }
}