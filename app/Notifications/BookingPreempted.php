<?php
    
namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class BookingPreempted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $preemptedBooking;

    public function __construct(Booking $preemptedBooking)
    {
        $this->preemptedBooking = $preemptedBooking;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database']; // Or just 'mail'
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Important: Your Resource Booking Has Been Preempted')
                    ->greeting('Dear ' . ($notifiable->first_name ?? $notifiable->name) . ',')
                    ->line('We regret to inform you that your booking for **' . $this->preemptedBooking->resource->name . '** from **' . $this->preemptedBooking->start_time->format('Y-m-d H:i') . '** to **' . $this->preemptedBooking->end_time->format('Y-m-d H:i') . '** has been preempted.')
                    ->line('This occurred due to a **higher priority university activity or class** requiring the resource at that time, as per our campus resource booking policy.')
                    ->line('We understand this may cause inconvenience and apologize for the short notice.')
                    ->action('View Your Bookings', url('/my-bookings'))
                    ->line('If you need assistance rebooking, please contact campus administration.')
                    ->salutation('Sincerely, The Campus Resource Booking Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->preemptedBooking->id,
            'resource_name' => $this->preemptedBooking->resource->name,
            'start_time' => $this->preemptedBooking->start_time->toDateTimeString(),
            'end_time' => $this->preemptedBooking->end_time->toDateTimeString(),
            'status' => 'preempted',
            'message' => 'Your booking was preempted by a higher priority event.',
        ];
    }
}