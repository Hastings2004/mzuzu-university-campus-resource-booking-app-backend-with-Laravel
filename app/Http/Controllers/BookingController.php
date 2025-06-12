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

        $query = $user->bookings()->with(['resource', 'user']);

        // Admin can see all bookings
        if ($user->user_type === 'admin') {
            $query = Booking::with(['resource', 'user']); // Admin sees all bookings
        } else {
            // Regular users only see their own future/active bookings
            $query->where('end_time', '>', Carbon::now())
                  ->whereIn('status', [
                      BookingService::STATUS_PENDING,
                      BookingService::STATUS_APPROVED,
                      BookingService::STATUS_IN_USE
                  ]);
        }

        // Apply filters
        if ($request->has('status') && in_array($request->query('status'), [
            BookingService::STATUS_PENDING,
            BookingService::STATUS_APPROVED,
            BookingService::STATUS_REJECTED,
            BookingService::STATUS_CANCELLED,
            BookingService::STATUS_COMPLETED,
            BookingService::STATUS_IN_USE,
            BookingService::STATUS_PREEMPTED,
        ])) {
            $query->where('status', $request->query('status'));
        }

        // Apply priority filter (if 'priority_level' exists on Booking model)
        if ($request->has('priority_level')) {
            $priorityValue = (int) $request->query('priority_level'); // Cast to int
            $query->where('priority_level', $priorityValue);
        }


        // Apply sorting
        $sortBy = $request->query('sort_by', 'start_time'); // Default sort by start_time
        $sortOrder = $request->query('order', 'asc'); // Default order ascending

        // Whitelist allowed sort columns to prevent SQL injection
        $allowedSortColumns = ['start_time', 'end_time', 'created_at', 'status', 'priority_level'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'start_time'; // Fallback to safe default
        }

        // Sanitize sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'asc';

        $bookings = $query->orderBy($sortBy, $sortOrder)->get();

        return response()->json([
            'success' => true,
            "bookings" => $bookings->map(function ($booking) {
                // Ensure all dates are ISO strings for consistency
                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'user_id' => $booking->user_id,
                    'resource_id' => $booking->resource_id,
                    'start_time' => $booking->start_time->toISOString(),
                    'end_time' => $booking->end_time->toISOString(),
                    'status' => $booking->status,
                    'purpose' => $booking->purpose,
                    'booking_type' => $booking->booking_type,
                    'priority_level' => $booking->priority_level,
                    'created_at' => $booking->created_at->toISOString(),
                    'resource' => [
                        'id' => $booking->resource->id,
                        'name' => $booking->resource->name,
                        'location' => $booking->resource->location ?? 'Unknown Location',
                        'description' => $booking->resource->description,
                        'capacity' => $booking->resource->capacity,
                        'type' => $booking->resource->type ?? null,
                        'is_active' => $booking->resource->is_active ?? null,
                    ],
                    'user_info' => [ // Include user info under a nested key
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
}
