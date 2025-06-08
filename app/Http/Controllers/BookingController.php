<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Resource;
use App\Serveces\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    // public function __construct()
    // {
    //     $this->middleware('auth:sanctum');
    // }

    /**
     * Display a listing of user's bookings
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $bookings = $request->user()->bookings()
            ->with(['resource', 'user']) // Add 'user' to eager loading
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            "bookings" => $bookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'user_id' => $booking->user_id,
                    'resource_id' => $booking->resource_id,
                    'start_time' => $booking->start_time->toISOString(),
                    'end_time' => $booking->end_time->toISOString(),
                    'status' => $booking->status,
                    'purpose' => $booking->purpose,
                    'created_at' => $booking->created_at->toISOString(),
                    
                    // Send full resource object instead of just the name
                    'resource' => [
                        'id' => $booking->resource->id,
                        'name' => $booking->resource->name,
                        'location' => $booking->resource->location ?? 'Unknown Location',
                        'description' => $booking->resource->description,
                        'capacity' => $booking->resource->capacity,
                        'type' => $booking->resource->type ?? null,
                    ],
                    
                    'first_name' => $booking->user->first_name ?? 'N/A',
                    'last_name' => $booking->user->last_name ?? "N/A",
                    'email' => $booking->user->email
                ];
            })
        ]);
    }
    // public function index()
    // {
    //     $user = Auth::user();
    //     $bookings = $user->bookings()
    //         ->with('resource')
    //         ->orderBy('created_at', 'desc')
    //         ->get();

    //     return response()->json([
    //         'success' => true,
    //          "bookings"=> $bookings->map(function ($booking) {
    //             return [
    //                 'id' => $booking->id,
    //                 'resource' => $booking->resource->name,
    //                 'start_time' => $booking->start_time->toISOString(),
    //                 'end_time' => $booking->end_time->toISOString(),
    //                 'status' => $booking->status,
    //                 'purpose' => $booking->purpose,
    //                 'user_name' => $booking->user->name ?? 'N/A',
    //                 'created_at' => $booking->created_at->toISOString(),
    //                 // Add other fields as needed
    //             ];
    //         })
    //     ]);
    // }

        /**
     * Store a newly created booking
     */
    public function store(StoreBookingRequest $request)
    {
        try {
            $user = $request->user();
            $resourceId = $request->resource_id;
            $startTime = Carbon::parse($request->start_time);
            $endTime = Carbon::parse($request->end_time);

            // Validate start time is in future
            if ($startTime <= Carbon::now()) {
                return response()->json(['message' => 'Booking start time must be in the future.'], 422);
            }

            // End time should be greater than start time
            if ($endTime->lessThanOrEqualTo($startTime)) {
                return response()->json(['message' => 'End time must be greater than start time.'], 422);
            }

            // Calculate booking duration
            $durationInMinutes = $endTime->diffInMinutes($startTime);

            if ($startTime->diffInMinutes($endTime) < 30) {
                return response()->json(['message' => 'Booking duration must be at least 30 minutes.'], 422);               
            }

            // Check duration constraints
            // if ($durationInMinutes < 30) {
            //     return response()->json(['message' => 'Booking duration must be at least 30 minutes.'], 422);
            // }

            if ($durationInMinutes > (8 * 60)) {
                return response()->json(['message' => 'Booking duration cannot exceed 8 hours.'], 422);
            }

            // Check user active bookings limit
            $activeBookingsCount = $user->bookings()
                ->whereIn('status', ['approved', 'pending'])
                ->where('end_time', '>', Carbon::now())
                ->count();

            if ($activeBookingsCount >= 5) {
                return response()->json(['message' => 'You have reached the maximum limit of 5 active bookings.'], 422);
            }

            // Check if resource exists and is active
            $resource = Resource::find($resourceId);
            if (!$resource) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }

            if (!$resource->is_active) {
                return response()->json(['message' => 'The selected resource is currently not active.'], 422);
            }

            // Check for overlapping bookings - FIXED LOGIC
            $overlappingBookings = Booking::where('resource_id', $resourceId)
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
                })
                ->count();

            if ($overlappingBookings > 0) {
                return response()->json(['message' => 'The resource is not available during the requested time slot.'], 422);
            }

            // Generate unique booking reference
            $bookingReference = $this->generateBookingReference();

            // Create booking
            $booking = $user->bookings()->create([
                "booking_reference" => $bookingReference, // Fixed: using booking_reference instead of reference
                "resource_id" => $request->resource_id,
                "start_time" => $startTime,
                "end_time" => $endTime,
                "status" => "approved",
                "purpose" => $request->purpose,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking created successfully.',
                'booking' => [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'resource' => $booking->resource,
                    'start_time' => $booking->start_time->toISOString(),
                    'end_time' => $booking->end_time->toISOString(),
                    'status' => $booking->status,
                    'purpose' => $booking->purpose,
                    'created_at' => $booking->created_at->toISOString(),
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Booking creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the booking.'
            ], 500);
        }
    }
    // public function store(Request $request)
    // {
    //     // Logic to store a new reservation
    //     $request->validate([
    //         'resource_id' => 'required|exists:resources,id',
    //         'start_time' => 'required|date|after_or_equal:now',
    //         'end_time' => 'required|date|after:start_time',
    //         'purpose' => 'required|string|max:500',
    //     ]);
       
    //     $booking = Auth::user()->bookings()->create([
    //         'booking_reference' => $this->generateBookingReference(),
    //         'resource_id' => $request->resource_id,
    //         'start_time' => $request->start_time,
    //         'end_time' => $request->end_time,
    //         'status' => Booking::STATUS_APPROVED, // Default status
    //         'purpose' => $request->purpose,
    //     ]);

    //     return response()->json([
    //         'message' => 'Reservation created successfully',
    //         'booking' => $booking
    //     ], 201);
    // }

    /**
     * Display the specified booking
     */
    public function show(Booking $booking)
    {
        // Check if user owns the booking
        if ($booking->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'booking' => $booking->load('resource')
        ]);
    }

    /**
     * Update the specified booking
     */
    public function update(UpdateBookingRequest $request, Booking $booking)
    {
        // Check if user owns the booking
        if ($booking->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if booking can be modified
        if ($booking->start_time <= Carbon::now()) {
            return response()->json(['message' => 'Cannot modify bookings that have already started.'], 422);
        }

        if ($booking->status === 'cancelled') {
            return response()->json(['message' => 'Cannot modify cancelled bookings.'], 422);
        }

        try {
            $booking->fill($request->validated());
            $booking->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Booking updated successfully.',
                'booking' => $booking->fresh()->load('resource')
            ]);

        } catch (\Exception $e) {
            Log::error('Booking update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the booking.'
            ], 500);
        }
    }

    /**
     * Cancel the specified booking
     */
    public function destroy(Booking $booking)
    {
        // Check if user owns the booking
        if ($booking->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if booking can be cancelled
        if ($booking->start_time <= Carbon::now()) {
            return response()->json(['message' => 'Cannot cancel bookings that have already started.'], 422);
        }

        try {
            $booking->update(['status' => 'cancelled']);
            
            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Booking cancellation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while cancelling the booking.'
            ], 500);
        }
    }

    /**
     * Generate a unique booking reference
     */
    private function generateBookingReference()
    {
        do {
            $reference = 'MZUNI-RBA-' . now()->format('dmHi') . '-' . strtoupper(Str::random(4));
        } while (Booking::where('booking_reference', $reference)->exists());

        return $reference;
    }

    //  public function checkAvailability(Request $request)
    //  {
    //      $request->validate([
    //          'resource_id' => 'required|exists:resources,id',
    //          'start_time' => 'required|date',
    //          'end_time' => 'required|date|after:start_time',
    //          'exclude_booking_id' => 'sometimes|nullable|exists:bookings,id', // Add this validation
    //     ]);

    //      $resourceId = $request->input('resource_id');
    //      $startTime = Carbon::parse($request->input('start_time'));
    //      $endTime = Carbon::parse($request->input('end_time'));
    //      $excludeBookingId = $request->input('exclude_booking_id'); // Get the ID

    //      // Retrieve resource capacity
    //     $resource = Resource::find($resourceId);
    //     if (!$resource) {
    //         return response()->json(['message' => 'Resource not found.'], 404);
    //     }
    //      $resourceCapacity = $resource->capacity ?? 1; // Default to 1 if capacity is not set

    //      // Call the modified private function
    //      $conflictResult = $this->checkBookingConflicts(
    //          $resourceId,
    //          $startTime,
    //          $endTime,
    //          $resourceCapacity,
    //          $excludeBookingId // Pass the exclude ID
    //      );

    //      if ($conflictResult['hasConflict']) {
    //          return response()->json([
    //              'success' => false, // Explicitly false for API consistency
    //              'message' => $conflictResult['message'],
    //              'conflicts' => $conflictResult['conflicts'] ?? [] // Return conflicts if any
    //          ], 409); // Use 409 Conflict status code
    //      }

    //      return response()->json([
    //          'success' => true,
    //          'message' => 'Time slot is available.',
    //          'conflicts' => []
    //      ], 200);
    // }



    private function checkBookingConflicts($resourceId, Carbon $startTime, Carbon $endTime, $resourceCapacity = 1, $excludeBookingId = null)
    {
        // Cache key for conflict check (include exclude_booking_id for proper caching)
        $cacheKey = "booking_conflicts_{$resourceId}_{$startTime->format('YmdHi')}_{$endTime->format('YmdHi')}" . 
                    ($excludeBookingId ? "_{$excludeBookingId}" : '');

        // Temporarily disable caching for this example to show the dynamic priority logic.
        // In a real application, you'd need a more sophisticated cache invalidation
        // if a booking gets preempted or its priority changes.
        // return Cache::remember($cacheKey, 300, function () use ($resourceId, $startTime, $endTime, $resourceCapacity, $excludeBookingId) {

            // Optimized query with proper indexing
            $conflictingBookingsQuery = Booking::where('resource_id', $resourceId)
                ->whereIn('status', ['pending', 'approved']) // Only consider active bookings for conflicts
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where(function ($q) use ($startTime, $endTime) {
                        // Existing booking starts during new booking's time slot
                        $q->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                    });
                })
                ->select(['id', 'start_time', 'end_time', 'user_id', 'priority']); // Select priority here

            // Exclude current booking if editing an existing booking
            if ($excludeBookingId) {
                $conflictingBookingsQuery->where('id', '!=', $excludeBookingId);
            }

            $conflictingBookings = $conflictingBookingsQuery->with(['user:id,name'])->get();

            $conflictCount = $conflictingBookings->count();

            // For resources with capacity 1, if there's any conflicting booking, it's a conflict.
            if ($resourceCapacity == 1 && $conflictCount > 0) {
                // Return all conflicting bookings, including their priorities,
                // so the calling method can decide on preemption.
                return [
                    'available' => false,
                    'hasConflict' => true,
                    'message' => 'Resource is already booked during this time.',
                    'conflicts' => $conflictingBookings->map(function ($booking) {
                        return [
                            'id' => $booking->id, // Important to return booking ID
                            'start_time' => $booking->start_time->format('Y-m-d H:i'),
                            'end_time' => $booking->end_time->format('Y-m-d H:i'),
                            'user' => $booking->user->name ?? 'Unknown',
                            'priority' => $booking->priority // Include priority
                        ];
                    })
                ];
            }

            // For resources with capacity > 1, check if the capacity is exceeded.
            if ($conflictCount >= $resourceCapacity) {
                return [
                    'available' => false,
                    'hasConflict' => true,
                    'message' => sprintf(
                        'Resource capacity (%d) is fully booked for the selected time period.',
                        $resourceCapacity
                    ),
                    'conflicts' => $conflictingBookings->map(function ($booking) {
                        return [
                            'id' => $booking->id,
                            'start_time' => $booking->start_time->format('Y-m-d H:i'),
                            'end_time' => $booking->end_time->format('Y-m-d H:i'),
                            'user' => $booking->user->name ?? 'Unknown',
                            'priority' => $booking->priority
                        ];
                    })
                ];
            }

            return [
                'available' => true,
                'hasConflict' => false,
                'message' => 'Time slot is available',
                'conflicts' => []
            ];

        // }); // End of Cache::remember block
    }

    // API endpoint method that matches your frontend call
    public function checkAvailability(Request $request)
    {
        $request->validate([
            'resource_id' => 'required|integer|exists:resources,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'exclude_booking_id' => 'nullable|integer|exists:bookings,id'
        ]);

        $resourceId = $request->input('resource_id');
        $startTime = Carbon::parse($request->input('start_time'));
        $endTime = Carbon::parse($request->input('end_time'));
        $excludeBookingId = $request->input('exclude_booking_id');

        // Get resource capacity (assuming you have a resources table)
        $resource = Resource::find($resourceId);
        $resourceCapacity = $resource ? $resource->capacity : 1;

        $result = $this->checkBookingConflicts(
            $resourceId, 
            $startTime, 
            $endTime, 
            $resourceCapacity, 
            $excludeBookingId
        );

        // Return appropriate HTTP status code
        if ($result['available']) {
            return response()->json($result, 200); // Available
        } else {
            return response()->json($result, 409); // Conflict
        }
    }

     public function getUserBookings(Request $request)
    {
        try {
            $perPage = min($request->get('per_page', 10), 50); // Limit per page
            $status = $request->get('status');
            $upcoming = $request->get('upcoming', false);

            $query = $request->user()->bookings()
                ->with(['resource:id,name,location'])
                ->select(['id', 'resource_id', 'start_time', 'end_time', 'status', 'purpose', 'created_at']);

            if ($status) {
                $query->where('status', $status);
            }

            if ($upcoming) {
                $query->where('start_time', '>', now());
            }

            $bookings = $query->orderBy('start_time', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'bookings' => $bookings->items(),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user bookings', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings'
            ], 500);
        }
    }

        /**
     * Cancel a booking
     */
    public function cancelBooking(Request $request, $bookingId)
    {
        try {
            // Find the booking
            $booking = Booking::findOrFail($bookingId);

            // Check if user owns the booking
            if ($booking->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to cancel this booking.'
                ], 403);
            }

            // Check if booking can be cancelled
            if (!$booking->canBeCancelled()) {
                $reason = $booking->isExpired() ? 'expired' : 'already cancelled or completed';
                return response()->json([
                    'success' => false,
                    'message' => "Cannot cancel booking - it is {$reason}."
                ], 400);
            }

            // Validate cancellation reason (optional)
            $request->validate([
                'reason' => 'nullable|string|max:500'
            ]);

            // Cancel the booking
            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => $request->input('reason')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully.',
                'booking' => $booking->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while cancelling the booking.'
            ], 500);
        }
    }

    /**
     * Get user's cancellable bookings
     */
    public function getCancellableBookings()
    {
        $bookings = Booking::where('user_id', Auth::id())
            ->active()
            ->notExpired()
            ->orderBy('booking_date')
            ->get();

        return response()->json([
            'success' => true,
            'bookings' => $bookings
        ]);
    }

    /**
     * Check if a specific booking can be cancelled
     */
    public function checkCancellationEligibility($bookingId)
    {
        try {
            $booking = Booking::findOrFail($bookingId);

            if ($booking->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'can_cancel' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }

            $canCancel = $booking->canBeCancelled();
            $reason = '';

            if (!$canCancel) {
                if ($booking->isExpired()) {
                    $reason = 'Booking has expired';
                } elseif ($booking->status === Booking::STATUS_CANCELLED) {
                    $reason = 'Booking is already cancelled';
                } else {
                    $reason = 'Booking cannot be cancelled';
                }
            }

            return response()->json([
                'success' => true,
                'can_cancel' => $canCancel,
                'message' => $reason,
                'booking_status' => $booking->status,
                'expires_at' => $booking->expires_at,
                'is_expired' => $booking->isExpired()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'can_cancel' => false,
                'message' => 'Booking not found.'
            ], 404);
        }
    }
}