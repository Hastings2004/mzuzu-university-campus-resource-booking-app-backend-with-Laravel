<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Http\Requests\StoreBookingRequest; // Make sure this Form Request exists
use App\Http\Requests\UpdateBookingRequest; // Make sure this Form Request exists
use App\Models\Resource; // Potentially still needed for some checks, but less so now
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
        // $this->middleware('auth:sanctum'); // Uncomment if you want to apply auth middleware to all methods
    }

    /**
     * Display a listing of user's bookings (or all for admin).
     * Now correctly applies filters and sorting based on user role.
     *
     * @param Request $request
     * @return JsonResponse
     */
    
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Base query - Admin sees all, regular user sees their own
        if ($user->user_type === 'admin') {
            $query = Booking::with(['resource', 'user']);
        } else {
            // Regular users see only their own bookings
            $query = $user->bookings()->with(['resource', 'user']);
        }

        // 1. Apply Status Filter (for both admin and regular users)
        $requestedStatus = $request->query('status');
        $allowedStatuses = [
            'pending',
            'approved',
            'rejected',
            'cancelled',
            'completed',
            'in_use',
            'expired',
            'preempted'
        ]; // Assuming these are your valid statuses

        if ($requestedStatus && $requestedStatus !== 'all' && in_array($requestedStatus, $allowedStatuses)) {
            $query->where('status', $requestedStatus);
        }

        // Map frontend string priority to backend integer priority_level
        $priorityMap = [
            'low' => 1,    
            'medium' => 2, 
            'high' => 3,   
        ];

        $requestedPriority = $request->query('priority'); // Frontend sends 'priority'
        if ($requestedPriority && $requestedPriority !== 'all' && isset($priorityMap[$requestedPriority])) {
            $query->where('priority', $priorityMap[$requestedPriority]);
        }

        // 2. Apply Date Range Filter (for both admin and regular users)

        $sortBy = $request->query('sort_by', 'created_at'); // Default to 'created_at' for 'Newest First'
        $sortOrder = $request->query('order', 'desc'); // Default to 'desc' for 'Newest First'

        // Whitelist allowed sort columns to prevent SQL injection
        $allowedSortColumns = ['start_time', 'end_time', 'created_at', 'status', 'priority'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'created_at'; // Fallback to safe default
        }

        // Sanitize sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'desc'; // Default to 'desc'

        $query->orderBy($sortBy, $sortOrder);


        // 4. Implement Pagination (as discussed in previous interaction, good for 15 bookings per page)
        $perPage = (int) $request->query('per_page', 15); // Default 15 bookings per page
        $bookings = $query->paginate($perPage);

        // Map integer priority_level to string priority for frontend response
        $priorityLevelToStringMap = array_flip($priorityMap); // Invert the map for response

        // Prepare bookings data for consistent frontend consumption
        $formattedBookings = $bookings->getCollection()->map(function ($booking) use ($priorityLevelToStringMap) {
            return [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'user_id' => $booking->user_id,
                'resource_id' => $booking->resource_id,
                'start_time' => $booking->start_time ? $booking->start_time->toISOString() : null,
                'end_time' => $booking->end_time ? $booking->end_time->toISOString() : null,
                'status' => $booking->status,
                'purpose' => $booking->purpose,
                'booking_type' => $booking->booking_type,                
                //'priority' => $priorityLevelToStringMap[$booking->priority_level] ?? 'unknown',
                'created_at' => $booking->created_at ? $booking->created_at->toISOString() : null,
                'updated_at' => $booking->updated_at ? $booking->updated_at->toISOString() : null,
                'resource' => $booking->resource ? [
                    'id' => $booking->resource->id,
                    'name' => $booking->resource->name,
                    'location' => $booking->resource->location ?? 'Unknown Location',
                    'description' => $booking->resource->description,
                    'capacity' => $booking->resource->capacity,
                    'type' => $booking->resource->type ?? null,
                    'is_active' => $booking->resource->is_active ?? null,
                ] : null,
                // Ensure 'user' is always available, even if null for some reason (though it shouldn't be for bookings)
                'user' => $booking->user ? [
                    'id' => $booking->user->id,
                    'first_name' => $booking->user->first_name ?? 'N/A',
                    'last_name' => $booking->user->last_name ?? 'N/A',
                    'email' => $booking->user->email ?? 'N/A',
                    'user_type' => $booking->user->user_type ?? $booking->user->role?->name ?? 'N/A', // Prioritize user_type, fallback to role name
                ] : null
            ];
        });

        // Log for regular user (optional, as you had it)
        if ($user->user_type !== 'admin') {
            Log::info('User retrieved their bookings.', ['user_id' => $user->id]);
        }

        // Return paginated data
        return response()->json([
            'success' => true,
            'bookings' => $formattedBookings,
            'pagination' => [
                'total' => $bookings->total(),
                'per_page' => $bookings->perPage(),
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'from' => $bookings->firstItem(),
                'to' => $bookings->lastItem(),
            ],
        ]);
    }
    /**
     * Check resource availability.
     * This method now delegates to the BookingService.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'resource_id' => 'required|exists:resources,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'exclude_booking_id' => 'sometimes|nullable|exists:bookings,id',
        ]);

        $resourceId = $request->input('resource_id');
        $startTime = Carbon::parse($request->input('start_time'));
        $endTime = Carbon::parse($request->input('end_time'));
        $excludeBookingId = $request->input('exclude_booking_id');

        $result = $this->bookingService->checkAvailabilityStatus(
            $resourceId,
            $startTime,
            $endTime,
            $excludeBookingId
        );

        // Map status codes for response based on the service's result
        $statusCode = $result['available'] ? 200 : ($result['hasConflict'] ? 409 : 422);

        return response()->json($result, $statusCode);
    }

    /**
     * Store a newly created booking with priority scheduling.
     * This method now delegates fully to the BookingService.
     *
     * @param StoreBookingRequest $request
     * @return JsonResponse
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        $result = $this->bookingService->createBooking($validatedData, $user);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'booking' => $result['booking'] ?? null,
        ], $result['status_code']);
    }

    /**
     * Display the specified booking.
     *
     * @param Booking $booking
     * @return JsonResponse
     */
    public function show(Booking $booking): JsonResponse
    {
        // Policy check: ensure user owns the booking or is an admin/staff
        if ($booking->user_id !== Auth::id() && !(Auth::user() && (Auth::user()->user_type === 'admin' || Auth::user()->user_type === 'staff'))) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'booking' => $booking->load('resource', 'user') // Load resource and user info
        ]);
    }

    /**
     * Update the specified booking.
     * This method now delegates fully to the BookingService.
     *
     * @param UpdateBookingRequest $request
     * @param Booking $booking
     * @return JsonResponse
     */
    public function update(UpdateBookingRequest $request, Booking $booking): JsonResponse
    {
        // Policy check: ensure user owns the booking or is an admin
        if ($booking->user_id !== Auth::id() && !(Auth::user() && Auth::user()->user_type === 'admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = $request->user();
        $validatedData = $request->validated();

        $result = $this->bookingService->updateBooking($booking, $validatedData, $user);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'booking' => $result['booking'] ?? null,
        ], $result['status_code']);
    }

    /**
     * Cancel the specified booking.
     * This method now delegates fully to the BookingService.
     *
     * @param Booking $booking
     * @return JsonResponse
     */
    public function destroy(Booking $booking): JsonResponse
    {
        // Policy check: ensure user owns the booking or is an admin
        if ($booking->user_id !== Auth::id() && !(Auth::user() && Auth::user()->user_type === 'admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $result = $this->bookingService->cancelBooking($booking);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['status_code']);
    }

    /**
     * Get upcoming bookings for the authenticated user.
     *
     * @return JsonResponse
     */
    public function getUserBookings(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Fetch upcoming bookings for the user
        $upcomingBookings = $user->bookings()
            ->where('end_time', '>', Carbon::now())
            ->with(['resource', 'user'])
            ->orderBy('start_time', 'asc')
            ->get();

        if ($upcomingBookings->isEmpty()) {
            return response()->json(['message' => 'No upcoming bookings found.'], 404);
        }

        return response()->json([
            'success' => true,
            'upcoming_bookings' => $upcomingBookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'start_time' => $booking->start_time->toISOString(),
                    'end_time' => $booking->end_time->toISOString(),
                    'status' => $booking->status,
                    'resource' => [
                        'id' => $booking->resource->id,
                        'name' => $booking->resource->name,
                        'location' => $booking->resource->location ?? 'Unknown Location',
                        'description' => $booking->resource->description,
                        'capacity' => $booking->resource->capacity,
                        'type' => $booking->resource->type ?? null,
                        'is_active' => $booking->resource->is_active ?? null,
                    ],
                    'user_info' => [
                        'id' => $booking->user->id ?? null,
                        'first_name' => $booking->user->first_name ?? 'N/A',
                        'last_name' => $booking->user->last_name ?? "N/A",
                        'email' => $booking->user->email ?? 'N/A',
                        'user_type' => $booking->user->user_type ?? $booking->user->role?->name ?? 'N/A',
                    ]
                ];
            })
        ]);
    }
    /**
     * Get cancellable bookings for the authenticated user.
     *
     * @return JsonResponse
     */
    
}
