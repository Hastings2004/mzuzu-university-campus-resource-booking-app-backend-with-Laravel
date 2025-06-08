<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    /** @use HasFactory<\Database\Factories\BookingFactory> */
    use HasFactory;

    use HasFactory;

    protected $fillable = [
        'booking_reference',
        'resource_id',
        'start_time',
        'end_time',
        'status',
        'purpose',        
        'cancelled_at',
        'cancellation_reason'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    const STATUS_APPROVED = 'approved';
    const STATUS_PENDING = 'pending';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_COMPLETED = 'completed';

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with Resource (if you have a resources table)
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    // Check if booking is expired (based on end_time)
    public function isExpired()
    {
        return Carbon::now()->greaterThan($this->end_time);
    }

    // Check if booking has started
    public function hasStarted()
    {
        return Carbon::now()->greaterThanOrEqualTo($this->start_time);
    }

    // Check if booking can be cancelled
    public function canBeCancelled()
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PENDING]) 
               && !$this->hasStarted() 
               && !$this->isExpired();
    }

    // Scope for active bookings (approved/pending)
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING]);
    }

    // Scope for non-expired bookings
    public function scopeNotExpired($query)
    {
        return $query->where('end_time', '>', Carbon::now());
    }

    // Scope for not started bookings
    public function scopeNotStarted($query)
    {
        return $query->where('start_time', '>', Carbon::now());
    }

    // Scope for cancellable bookings
    public function scopeCancellable($query)
    {
        return $query->active()->notStarted()->notExpired();
    }


}
