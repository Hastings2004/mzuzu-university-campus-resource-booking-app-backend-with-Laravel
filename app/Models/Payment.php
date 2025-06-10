<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'amount',
        'payment_method',
        'transaction_id',
        'status',
    ];
    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', // Cast amount to decimal with 2 decimal places
            'status' => 'string', // Ensure status is treated as a string
        ];
    }
    /**
     * Get the booking associated with the payment.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }       

    /**
     * Get the user who made the payment.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
