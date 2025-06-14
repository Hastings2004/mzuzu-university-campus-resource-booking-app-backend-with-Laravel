<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder; // Import Builder for type hinting

class ReportService
{
    
    const BOOKING_STATUS_APPROVED = 'approved';
    const BOOKING_STATUS_IN_USE = 'in_use';
    const BOOKING_STATUS_COMPLETED = 'completed';

    /**
     * Calculates the utilization of resources within a specified date range.
     *
     * @param string|null $startDate The start date for the report (e.g., 'YYYY-MM-DD'). Defaults to start of current month.
     * @param string|null $endDate The end date for the report (e.g., 'YYYY-MM-DD'). Defaults to end of current month.
     * @return array An array of resource utilization data.
     */
    public function getResourceUtilizationReport(?string $startDate = null, ?string $endDate = null)
{
    try {
        // Default to current month if dates are not provided
        // IMPORTANT: Carbon::parse($startDate)->startOfDay() sets time to 00:00:00
        // IMPORTANT: Carbon::parse($endDate)->endOfDay() sets time to 23:59:59
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfMonth()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfMonth()->endOfDay();

        // Ensure start date is not after end date (this swap is good!)
        if ($startDate->greaterThan($endDate)) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }

        // --- DEBUGGING LOGS (add these back temporarily if you removed them) ---
        Log::debug("ReportService - Final Start Date: " . $startDate->toDateTimeString());
        Log::debug("ReportService - Final End Date: " . $endDate->toDateTimeString());
        // --- END DEBUGGING LOGS ---

        $resources = Resource::all();
        $reportData = [];

        // Calculate total reporting period duration in hours
        // If startDate is '2024-06-14 00:00:00' and endDate is '2024-06-14 23:59:59',
        // diffInHours will be 23. If both are exactly the same '00:00:00', it will be 0.
        $reportPeriodDurationHours = $endDate->diffInHours($startDate);

        // --- DEBUGGING LOG ---
        Log::debug("ReportService - Calculated report period duration in hours: " . $reportPeriodDurationHours);
        // --- END DEBUGGING LOG ---

        if ($reportPeriodDurationHours <= 0) {
            // This is the line returning the error.
            // It means $endDate->diffInHours($startDate) resulted in 0 or a negative number.
            return ['success' => false, 'message' => 'Invalid date range provided for the report.'];
        }

        // ... (rest of your foreach loop and calculations)

    } catch (\Exception $e) {
        Log::error("Error generating resource utilization report: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return ['success' => false, 'message' => 'An error occurred while generating the report.'];
    }}
}