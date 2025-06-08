<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class Reservation extends Controller
{
    //
    /**
     * Display a listing of the reservations.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Logic to display reservations


    }

    /**
     * Show the form for creating a new reservation.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Logic to show form for creating a new reservation
    }

    public function store(Request $request)
    {
        // Logic to store a new reservation
        $request->validate([
            'resource_id' => 'required|exists:resources,id',
            'start_time' => 'required|date|after_or_equal:now',
            'end_time' => 'required|date|after:start_time',
            'purpose' => 'required|string|max:500',
        ]);
       
        $booking = $request->user()->bookings()->create([
            'booking_reference' => 'BR-' . strtoupper(uniqid()),
            'resource_id' => $request->resource_id,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'status' => Booking::STATUS_PENDING, // Default status
            'purpose' => $request->purpose,
        ]);

        return response()->json([
            'message' => 'Reservation created successfully',
            'booking' => $booking
        ], 201);
    }
}
