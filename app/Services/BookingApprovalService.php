<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User; // Assuming User model is in App\Models
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BookingApprovalException;
use App\Notifications\BookingApproved; // Assuming these notifications exist
use App\Notifications\BookingRejected;
// For cancellation, you might reuse BookingRejected or create a specific one
// use App\Notifications\BookingCancelledByAdmin;

class BookingApprovalService
{
    /**
     * Get a paginated list of bookings based on status and filters.
     *
     * @param string $status
     * @param int $perPage
     * @param array $filters Additional filters like resource_id, user_id, etc.
     * @return LengthAwarePaginator
     * @throws BookingApprovalException
     */
    public function getBookingsForApproval(string $status = 'pending', int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        try {
            $query = Booking::with(['user:id,first_name,last_name,email', 'resource:id,name,location']) // Load resource too
                ->where('status', $status)
                ->orderBy('created_at', 'desc');

            // Apply additional filters if any
            if (isset($filters['resource_id'])) {
                $query->where('resource_id', $filters['resource_id']);
            }
            if (isset($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            // Add more filters as needed (e.g., start_time range)

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Error fetching bookings for approval: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new BookingApprovalException('Failed to retrieve bookings for approval.');
        }
    }

    /**
     * Approve a specific booking.
     *
     * @param int $bookingId
     * @param int $adminId
     * @param string|null $notes
     * @return Booking
     * @throws BookingApprovalException
     */
    public function approveBooking(int $bookingId, int $adminId, ?string $notes = null): Booking
    {
        DB::beginTransaction();
        try {
            $booking = Booking::findOrFail($bookingId);

            if ($booking->status !== Booking::STATUS_PENDING) { // Use constant from Booking model or define here
                throw new BookingApprovalException('Only pending bookings can be approved.', 400);
            }

            $booking->update([
                'status' => Booking::STATUS_APPROVED,
                'approved_by' => $adminId,
                'approved_at' => Carbon::now(),
                'admin_notes' => $notes,
            ]);

            // Notify the user
            if ($booking->user) {
                $booking->user->notify(new BookingApproved($booking));
            }

            Log::info("Booking {$bookingId} approved by admin {$adminId}");
            DB::commit();

            return $booking->fresh(['user', 'resource']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            throw new BookingApprovalException('Booking not found.', 404);
        } catch (BookingApprovalException $e) {
            DB::rollBack();
            throw $e; // Re-throw custom exceptions
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving booking: ' . $e->getMessage(), ['booking_id' => $bookingId, 'admin_id' => $adminId, 'trace' => $e->getTraceAsString()]);
            throw new BookingApprovalException('Failed to approve booking.');
        }
    }

    /**
     * Reject a specific booking.
     *
     * @param int $bookingId
     * @param int $adminId
     * @param string $reason
     * @param string|null $notes
     * @return Booking
     * @throws BookingApprovalException
     */
    public function rejectBooking(int $bookingId, int $adminId, string $reason, ?string $notes = null): Booking
    {
        DB::beginTransaction();
        try {
            $booking = Booking::findOrFail($bookingId);

            if (!in_array($booking->status, [Booking::STATUS_PENDING, Booking::STATUS_APPROVED])) {
                throw new BookingApprovalException('Only pending or approved bookings can be rejected.', 400);
            }

            $booking->update([
                'status' => Booking::STATUS_REJECTED,
                'rejected_by' => $adminId,
                'rejected_at' => Carbon::now(),
                'rejection_reason' => $reason,
                'admin_notes' => $notes,
            ]);

            // Notify the user
            if ($booking->user) {
                $booking->user->notify(new BookingRejected($booking));
            }

            Log::info("Booking {$bookingId} rejected by admin {$adminId}");
            DB::commit();

            return $booking->fresh(['user', 'resource']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            throw new BookingApprovalException('Booking not found.', 404);
        } catch (BookingApprovalException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting booking: ' . $e->getMessage(), ['booking_id' => $bookingId, 'admin_id' => $adminId, 'trace' => $e->getTraceAsString()]);
            throw new BookingApprovalException('Failed to reject booking.');
        }
    }

    /**
     * Cancel a specific booking by an admin.
     * Note: This is an admin-initiated cancellation, different from a user cancellation.
     *
     * @param int $bookingId
     * @param int $adminId
     * @param string $reason
     * @param float|null $refundAmount
     * @param string|null $notes
     * @return Booking
     * @throws BookingApprovalException
     */
    public function cancelBookingByAdmin(int $bookingId, int $adminId, string $reason, ?float $refundAmount = null, ?string $notes = null): Booking
    {
        DB::beginTransaction();
        try {
            $booking = Booking::findOrFail($bookingId);

            if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED, Booking::STATUS_REJECTED, Booking::STATUS_PREEMPTED])) {
                throw new BookingApprovalException('Cannot cancel a booking that is already ' . $booking->status . '.', 400);
            }
            // Prevent cancelling bookings that have already passed their end time
            if ($booking->end_time->lt(Carbon::now())) {
                throw new BookingApprovalException('Cannot cancel bookings that have already completed.', 400);
            }

            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_by' => $adminId,
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => $reason,
                'refund_amount' => $refundAmount,
                'admin_notes' => $notes,
            ]);

            // Notify the user (you might create a specific notification like BookingCancelledByAdmin)
            if ($booking->user) {
                $booking->user->notify(new BookingRejected($booking)); // Reusing Rejected for now
            }

            Log::info("Booking {$bookingId} cancelled by admin {$adminId}");
            DB::commit();

            return $booking->fresh(['user', 'resource']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            throw new BookingApprovalException('Booking not found.', 404);
        } catch (BookingApprovalException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling booking by admin: ' . $e->getMessage(), ['booking_id' => $bookingId, 'admin_id' => $adminId, 'trace' => $e->getTraceAsString()]);
            throw new BookingApprovalException('Failed to cancel booking.');
        }
    }

    /**
     * Get details of a specific booking for review.
     *
     * @param int $bookingId
     * @return Booking
     * @throws BookingApprovalException
     */
    public function getBookingDetails(int $bookingId): Booking
    {
        try {
            $booking = Booking::with([
                'user',
                'resource', // Changed from 'service' to 'resource'
                'approvedBy', // Relationship to User model for approved_by
                'rejectedBy', // Relationship to User model for rejected_by
                'cancelledBy' // Relationship to User model for cancelled_by
            ])->findOrFail($bookingId);

            return $booking;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new BookingApprovalException('Booking not found.', 404);
        } catch (\Exception $e) {
            Log::error('Error fetching booking details: ' . $e->getMessage(), ['booking_id' => $bookingId, 'trace' => $e->getTraceAsString()]);
            throw new BookingApprovalException('Failed to retrieve booking details.');
        }
    }

    /**
     * Bulk approve multiple bookings.
     *
     * @param array $bookingIds
     * @param int $adminId
     * @param string|null $notes
     * @return array Contains 'approved_count', 'total_requested', 'errors'
     */
    public function bulkApproveBookings(array $bookingIds, int $adminId, ?string $notes = null): array
    {
        $approvedCount = 0;
        $errors = [];

        DB::beginTransaction(); // Start a single transaction for the bulk operation

        try {
            $bookingsToApprove = Booking::whereIn('id', $bookingIds)
                ->where('status', Booking::STATUS_PENDING) // Only pending bookings can be bulk approved
                ->get();

            // Check if all requested IDs are pending and exist
            if ($bookingsToApprove->count() !== count($bookingIds)) {
                // Find IDs that are not pending or do not exist
                $missingOrNonPendingIds = array_diff($bookingIds, $bookingsToApprove->pluck('id')->toArray());
                foreach ($missingOrNonPendingIds as $id) {
                    $errors[] = "Booking #{$id} is not pending or does not exist.";
                }
                // If there are issues, we can either rollback the entire transaction
                // or proceed with valid ones and report errors. Given the original
                // controller's behavior, it seems to imply an "all or nothing" for the bulk
                // if there are *any* non-pending/missing.
                // For robustness, I'll allow partial success if some are valid.
                // If you prefer strict all-or-nothing, throw exception here.
            }

            foreach ($bookingsToApprove as $booking) {
                try {
                    // Directly update the booking here. Re-calling approveBooking() would start nested transactions.
                    $booking->update([
                        'status' => Booking::STATUS_APPROVED,
                        'approved_by' => $adminId,
                        'approved_at' => Carbon::now(),
                        'admin_notes' => $notes,
                    ]);

                    // Notify the user
                    if ($booking->user) {
                        $booking->user->notify(new BookingApproved($booking));
                    }
                    $approvedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to approve booking #{$booking->id}: " . $e->getMessage();
                    Log::error("Bulk approval failed for booking {$booking->id}: " . $e->getMessage());
                    // Don't rollback here, allow other bookings to be processed.
                }
            }
            DB::commit(); // Commit if no major exceptions occurred in the loop.

        } catch (\Exception $e) {
            DB::rollBack(); // Rollback if an exception occurred before the loop or during query.
            Log::error('Error during bulk approval transaction: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new BookingApprovalException('An unexpected error occurred during bulk approval.');
        }

        return [
            'approved_count' => $approvedCount,
            'total_requested' => count($bookingIds),
            'errors' => $errors,
        ];
    }
}
