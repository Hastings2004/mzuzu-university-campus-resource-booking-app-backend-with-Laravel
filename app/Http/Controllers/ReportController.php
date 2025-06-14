<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get the resource utilization report.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getResourceUtilization(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Authorization: Only admins can access reports
        if (!$user || $user->user_type !== 'admin') {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized to access reports."
            ], 403); // Use 403 Forbidden for authorization issues
        }

        // Validate incoming request for date parameters
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $report = $this->reportService->getResourceUtilizationReport($startDate, $endDate);

        if ($report['success']) {
            return response()->json([
                "success" => true,
                "message" => "Resource utilization report generated successfully.",
                "report" => $report['data'],
                "period" => [
                    "start_date" => $report['start_date'],
                    "end_date" => $report['end_date'],
                ]
            ], 200);
        } else {
            return response()->json([
                "success" => false,
                "message" => $report['message']
            ], 500); // Or 400 if it's a client-side error due to invalid dates
        }
    }
}