<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    /** @use HasFactory<\Database\Factories\ResourceFactory> */
    use HasFactory;

    
    protected $fillable = [
        'name',
        'description',
        'location',
        'capacity',
        'status',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'status' => 'string'
    ];

    /**
     * Get the bookings associated with the resource.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
    
}
