<?php

// app/Notifications/BookingRejected.php
namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRejected extends Notification implements ShouldQueue
{
    use Queueable;
    protected $booking;
    protected $reason;

    public function __construct(Booking $booking, string $reason = 'Conflict with an existing booking.')
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Update on Your Resource Booking Request')
                    ->greeting('Dear ' . ($notifiable->first_name ?? $notifiable->name) . ',')
                    ->line('We regret to inform you that your booking request for **' . $this->booking->resource->name . '** from **' . $this->booking->start_time->format('Y-m-d H:i') . '** to **' . $this->booking->end_time->format('Y-m-d H:i') . '** could not be approved.')
                    ->line('**Reason for rejection:** ' . $this->reason)
                    ->line('This often happens when the resource is already booked by an activity with higher or equal priority during your requested time.')
                    ->action('Browse Available Resources', url('/resources'))
                    ->line('Please consider booking an alternative time or resource. We apologize for any inconvenience.')
                    ->salutation('Sincerely, The Campus Resource Booking Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'resource_name' => $this->booking->resource->name,
            'start_time' => $this->booking->start_time->toDateTimeString(),
            'end_time' => $this->booking->end_time->toDateTimeString(),
            'status' => 'rejected',
            'message' => 'Your booking was rejected. Reason: ' . $this->reason,
        ];
    }
}