<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\DashboardService; // Import the new service
use App\Exceptions\DashboardException; // Import the custom exception

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        //$this->middleware('auth:sanctum'); // Uncomment if you want to apply auth middleware to all methods
        $this->dashboardService = $dashboardService;
        

        $user = Auth::user();

        if(!$user || $user->user_type != 'admin'){
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access. Only admins can view the dashboard.'
            ], 403); // Forbidden           
            
        }
        
    }

    /**
     * Display the dashboard data.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $dashboardData = $this->dashboardService->getAllDashboardData();

            return response()->json($dashboardData);
        } catch (DashboardException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500); // Use specific code or default to 500
        } catch (\Exception $e) {
            Log::error('DashboardController@index failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while fetching dashboard data.'
            ], 500);
        }
    }
}
