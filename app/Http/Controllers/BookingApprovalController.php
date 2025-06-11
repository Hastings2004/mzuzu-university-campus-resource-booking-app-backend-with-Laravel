<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use App\Models\Booking; 
use App\Services\BookingApprovalService; 
use App\Exceptions\BookingApprovalException; 
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingApprovalController extends Controller
{
    protected $bookingApprovalService;

    /**
     * Create a new controller instance.
     *
     * @param BookingApprovalService $bookingApprovalService
     */
    public function __construct(BookingApprovalService $bookingApprovalService)
    {
        // Inject the BookingApprovalService to handle business logic
        $this->bookingApprovalService = $bookingApprovalService;
        
        // Apply middleware to ensure only authenticated users can access these routes
        $this->middleware('auth:sanctum'); 
        
        // Middleware to check if the user is an admin
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || !(Auth::user()->user_type === 'admin' || Auth::user()->role?->name === 'admin')) {
                 return response()->json(['message' => 'Unauthorized access'], 403);
            }
            return $next($request);
        });
    }

    /**
     * Get all bookings for approval (or other statuses based on filter).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        // Authorization check
        if (!$user || !($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $status = $request->query('status', Booking::STATUS_PENDING); // Default to 'pending'
            $perPage = (int) $request->query('per_page', 15);
            $filters = $request->only(['resource_id', 'user_id']); // Extract other filters

            $bookings = $this->bookingApprovalService->getBookingsForApproval($status, $perPage, $filters);

            return response()->json([
                'success' => true,
                'data' => $bookings,
                'message' => 'Bookings retrieved successfully'
            ]);
        } catch (BookingApprovalException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('BookingApprovalController@index failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings'
            ], 500);
        }
    }

    /**
     * Approve a specific booking.
     *
     * @param Request $request
     * @param int $id Booking ID.
     * @return JsonResponse
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'notes' => 'nullable|string|max:500'
            ]);

            $approvedBooking = $this->bookingApprovalService->approveBooking(
                $id,
                $user->id,
                $validatedData['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $approvedBooking,
                'message' => 'Booking approved successfully'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (BookingApprovalException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400); // Bad Request for business logic errors, or 404 for not found
        } catch (\Exception $e) {
            Log::error('BookingApprovalController@approve failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve booking'
            ], 500);
        }
    }

    /**
     * Reject a specific booking.
     *
     * @param Request $request
     * @param int $id Booking ID.
     * @return JsonResponse
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'reason' => 'required|string|max:500',
                'notes' => 'nullable|string|max:500'
            ]);

            $rejectedBooking = $this->bookingApprovalService->rejectBooking(
                $id,
                $user->id,
                $validatedData['reason'],
                $validatedData['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $rejectedBooking,
                'message' => 'Booking rejected successfully'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (BookingApprovalException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400);
        } catch (\Exception $e) {
            Log::error('BookingApprovalController@reject failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject booking'
            ], 500);
        }
    }

    /**
     * Cancel a specific booking by an admin.
     *
     * @param Request $request
     * @param int $id Booking ID.
     * @return JsonResponse
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'reason' => 'required|string|max:500',
                'refund_amount' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string|max:500'
            ]);

            $cancelledBooking = $this->bookingApprovalService->cancelBookingByAdmin(
                $id,
                $user->id,
                $validatedData['reason'],
                $validatedData['refund_amount'] ?? null,
                $validatedData['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $cancelledBooking,
                'message' => 'Booking cancelled successfully'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (BookingApprovalException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400);
        } catch (\Exception $e) {
            Log::error('BookingApprovalController@cancel failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel booking'
            ], 500);
        }
    }

    /**
     * Get booking details for approval review.
     *
     * @param int $id Booking ID.
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $booking = $this->bookingApprovalService->getBookingDetails($id);

            return response()->json([
                'success' => true,
                'data' => $booking,
                'message' => 'Booking details retrieved successfully'
            ]);
        } catch (BookingApprovalException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 404);
        } catch (\Exception $e) {
            Log::error('BookingApprovalController@show failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve booking details'
            ], 500);
        }
    }

    /**
     * Bulk approve bookings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        try {
            $request->validate([
                'booking_ids' => 'required|array|min:1',
                'booking_ids.*' => 'integer|exists:bookings,id',
                'notes' => 'nullable|string|max:500'
            ]);

            $result = $this->bookingApprovalService->bulkApproveBookings(
                $request->booking_ids,
                $user->id,
                $request->notes ?? null
            );

            // Determine appropriate status code based on partial success or full failure
            $statusCode = 200; // Default to OK
            if ($result['total_requested'] > 0 && $result['approved_count'] === 0) {
                $statusCode = 400; // All failed
            } elseif ($result['approved_count'] > 0 && $result['approved_count'] < $result['total_requested']) {
                $statusCode = 207; // Partial Content (Multi-Status) - if API client understands it, otherwise 200
            }

            return response()->json([
                'success' => true, // Or false if all failed
                'data' => $result,
                'message' => $result['approved_count'] > 0
                    ? "Successfully approved {$result['approved_count']} bookings. " . (count($result['errors']) > 0 ? "Errors occurred for some: " . implode(', ', $result['errors']) : '')
                    : (count($result['errors']) > 0 ? "No bookings approved. Errors: " . implode(', ', $result['errors']) : 'No bookings were selected or are pending for approval.'),
            ], $statusCode);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (BookingApprovalException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400);
        } catch (\Exception $e) {
            Log::error('BookingApprovalController@bulkApprove failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk approval.'
            ], 500);
        }
    }
}
