<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingApprovalController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        // $this->middleware('auth');
        // $this->middleware('admin'); // Assuming you have admin middleware
    }

    /**
     * Get all pending bookings for approval
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status', 'pending');
            
            $bookings = Booking::with(['user', 'service']) // Adjust relationships as needed
                ->where('status', $status)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $bookings,
                'message' => 'Bookings retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching bookings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings'
            ], 500);
        }
    }

    /**
     * Approve a booking
     */
    public function approve(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            $booking = Booking::findOrFail($id);

            // Check if booking can be approved
            if ($booking->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending bookings can be approved'
                ], 400);
            }

            // Update booking status
            $booking->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'admin_notes' => $request->notes
            ]);

            // Log the approval
            Log::info("Booking {$id} approved by admin " . Auth::id());

            // You can add additional logic here such as:
            // - Send notification to customer
            // - Send confirmation email
            // - Update related resources/schedules
            // - Trigger webhooks

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $booking->fresh(['user', 'service']),
                'message' => 'Booking approved successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving booking: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve booking'
            ], 500);
        }
    }

    /**
     * Reject a booking
     */
    public function reject(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:500'
            ]);

            DB::beginTransaction();

            $booking = Booking::findOrFail($id);

            // Check if booking can be rejected
            if (!in_array($booking->status, ['pending', 'approved'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending or approved bookings can be rejected'
                ], 400);
            }

            // Update booking status
            $booking->update([
                'status' => 'rejected',
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
                'rejection_reason' => $request->reason,
                'admin_notes' => $request->notes ?? null
            ]);

            // Log the rejection
            Log::info("Booking {$id} rejected by admin " . Auth::id());

            // Additional logic for rejection:
            // - Send notification to customer
            // - Process refund if payment was made
            // - Free up reserved resources/time slots
            // - Send rejection email with reason

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $booking->fresh(['user', 'service']),
                'message' => 'Booking rejected successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting booking: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject booking'
            ], 500);
        }
    }

    /**
     * Cancel a booking
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:500',
                'refund_amount' => 'nullable|numeric|min:0'
            ]);

            DB::beginTransaction();

            $booking = Booking::findOrFail($id);

            // Check if booking can be cancelled
            if (in_array($booking->status, ['cancelled', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel a booking that is already cancelled or completed'
                ], 400);
            }

            // Update booking status
            $booking->update([
                'status' => 'cancelled',
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now(),
                'cancellation_reason' => $request->reason,
                'refund_amount' => $request->refund_amount,
                'admin_notes' => $request->notes ?? null
            ]);

            // Log the cancellation
            Log::info("Booking {$id} cancelled by admin " . Auth::id());

            // Additional logic for cancellation:
            // - Process refund if applicable
            // - Send notification to customer
            // - Free up reserved resources/time slots
            // - Send cancellation email
            // - Update inventory/availability

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $booking->fresh(['user', 'service']),
                'message' => 'Booking cancelled successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling booking: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel booking'
            ], 500);
        }
    }

    /**
     * Get booking details for approval review
     */
    public function show($id): JsonResponse
    {
        try {
            $booking = Booking::with([
                'user',
                'service',
                'approvedBy',
                'rejectedBy',
                'cancelledBy'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $booking,
                'message' => 'Booking details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching booking details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }
    }

    /**
     * Bulk approve bookings
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'booking_ids' => 'required|array|min:1',
                'booking_ids.*' => 'integer|exists:bookings,id',
                'notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            $bookings = Booking::whereIn('id', $request->booking_ids)
                ->where('status', 'pending')
                ->get();

            if ($bookings->count() !== count($request->booking_ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some bookings are not pending or do not exist'
                ], 400);
            }

            $bookings->each(function ($booking) use ($request) {
                $booking->update([
                    'status' => 'approved',
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'admin_notes' => $request->notes
                ]);
            });

            Log::info("Bulk approval of " . $bookings->count() . " bookings by admin " . Auth::id());

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => ['approved_count' => $bookings->count()],
                'message' => "Successfully approved {$bookings->count()} bookings"
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in bulk approval: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve bookings'
            ], 500);
        }
    }
}