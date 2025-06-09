<?php
/*
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking; 
use App\Models\Resource; 
use App\Models\User;     
use Illuminate\Support\Facades\DB; 
use Carbon\Carbon; 

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Authorization Check
        if ($request->user()->user_type !== 'admin') {
            return response()->json(['message' => 'Unauthorized access. Only administrators can view this dashboard.'], 403);
        }

        // 2. Fetch Key Metrics (KPIs)
        $totalResources = Resource::count();
        $totalBookings = Booking::count();
        $totalUsers = User::count();

        // Calculate available resources more accurately if possible
        $availableResources = Resource::where('status', 'available')
            ->whereDoesntHave('bookings', function ($query) {
                $query->where('end_time', '>', Carbon::now())
                      ->whereIn('status', ['approved', 'pending']); // Consider pending if it affects availability
            })
            ->count();

        // 3. Fetch Chart Data

        // Bookings by Status
        $bookingsByStatus = Booking::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Resource Availability Overview (Example: available, maintenance)
        // This fetches counts for resources based on their primary status.
        $resourceAvailability = Resource::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                $name = ucfirst($item->status);
                // You can customize display names if needed
                if ($item->status === 'available') $name = 'Available';
                if ($item->status === 'maintenance') $name = 'Under Maintenance';
                // Add any other resource statuses you have (e.g., 'unavailable', 'decommissioned')
                return ['name' => $name, 'count' => $item->count];
            })
            ->toArray();

        // Adding 'Currently Booked' into resourceAvailability for a more complete picture.
        // This count is based on actual bookings, not a static resource status.
        $currentlyBookedResourcesCount = DB::table('bookings')
            ->where('end_time', '>', Carbon::now())
            ->where('start_time', '<', Carbon::now())
            ->where('status', 'approved')
            ->distinct('resource_id')
            ->count('resource_id');

        // Add 'Currently Booked' to the resourceAvailability array if it's not already there
        $foundBookedStatus = false;
        foreach ($resourceAvailability as &$statusItem) {
            if ($statusItem['name'] === 'Currently Booked') { // Check for existing 'Currently Booked'
                $statusItem['count'] += $currentlyBookedResourcesCount;
                $foundBookedStatus = true;
                break;
            }
        }
        if (!$foundBookedStatus) {
            $resourceAvailability[] = ['name' => 'Currently Booked', 'count' => $currentlyBookedResourcesCount];
        }


        // Top 5 Most Booked Resources
        $topBookedResources = DB::table('bookings')
            ->join('resources', 'bookings.resource_id', '=', 'resources.id')
            ->select('resources.id as resource_id', 'resources.name as resource_name', DB::raw('count(bookings.id) as total_bookings'))
            ->groupBy('resources.id', 'resources.name')
            ->orderByDesc('total_bookings')
            ->limit(5)
            ->get()
            ->toArray(); // Ensure it's an array for consistency

        // Monthly Booking Trends
        $monthlyBookings = Booking::select(
                DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                DB::raw('count(*) as total_bookings')
            )
            ->whereYear('start_time', Carbon::now()->year) // Get data for the current year
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();

        // **NEW: Resource Utilization (Total Booked Hours) Over Time**
        // This calculates the total duration of bookings per month.
        // Assumes 'start_time' and 'end_time' are datetime columns in your 'bookings' table.
        $resourceUtilizationMonthly = Booking::select(
                DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                DB::raw("SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) as total_booked_hours")
            )
            ->whereYear('start_time', Carbon::now()->year) // For current year
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();

        // 4. Return the Data
        return response()->json([
            'total_resources' => $totalResources,
            'total_bookings' => $totalBookings,
            'total_users' => $totalUsers,
            'available_resources' => $availableResources,
            'bookings_by_status' => $bookingsByStatus,
            'resource_availability' => $resourceAvailability,
            'top_booked_resources' => $topBookedResources,
            'monthly_bookings' => $monthlyBookings,
            'resource_utilization_monthly' => $resourceUtilizationMonthly, // NEW DATA
        ]);
    }
} */


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Display dashboard analytics data for admin users.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->unauthorizedResponse();
        }

        $dashboardData = [
            'kpis' => $this->getKeyMetrics(),
            'charts' => $this->getChartData(),
        ];

        return response()->json([
            'success' => true,
            'data' => $dashboardData,
            'message' => 'Dashboard data retrieved successfully'
        ]);
    }

    /**
     * Get key performance indicators (KPIs).
     */
    private function getKeyMetrics(): array
    {
        return [
            'total_resources' => Resource::count(),
            'total_bookings' => Booking::count(),
            'total_users' => User::count(),
            'available_resources' => $this->getAvailableResourcesCount(),
        ];
    }

    /**
     * Get all chart data for the dashboard.
     */
    private function getChartData(): array
    {
        return [
            'bookings_by_status' => $this->getBookingsByStatus(),
            'resource_availability' => $this->getResourceAvailability(),
            'top_booked_resources' => $this->getTopBookedResources(),
            'monthly_bookings' => $this->getMonthlyBookings(),
            'resource_utilization_monthly' => $this->getResourceUtilizationMonthly(),
        ];
    }

    /**
     * Count resources that are currently available.
     */
    private function getAvailableResourcesCount(): int
    {
        return Resource::where('status', 'available')
            ->whereDoesntHave('bookings', function ($query) {
                $query->where('end_time', '>', Carbon::now())
                      ->whereIn('status', ['approved', 'pending']);
            })
            ->count();
    }

    /**
     * Get booking counts grouped by status.
     */
    private function getBookingsByStatus(): array
    {
        return Booking::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get resource availability overview with better formatting.
     */
    private function getResourceAvailability(): array
    {
        $availability = Resource::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $this->formatResourceStatus($item->status),
                    'count' => $item->count
                ];
            })
            ->toArray();

        // Add currently booked resources
        $currentlyBookedCount = $this->getCurrentlyBookedResourcesCount();
        if ($currentlyBookedCount > 0) {
            $availability[] = [
                'name' => 'Currently Booked',
                'count' => $currentlyBookedCount
            ];
        }

        return $availability;
    }

    /**
     * Format resource status for display.
     */
    private function formatResourceStatus(string $status): string
    {
        $statusMap = [
            'available' => 'Available',
            'maintenance' => 'Under Maintenance',
            'unavailable' => 'Unavailable',
            'decommissioned' => 'Decommissioned',
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }

    /**
     * Get count of currently booked resources.
     */
    private function getCurrentlyBookedResourcesCount(): int
    {
        return DB::table('bookings')
            ->where('end_time', '>', Carbon::now())
            ->where('start_time', '<=', Carbon::now())
            ->where('status', 'approved')
            ->distinct('resource_id')
            ->count('resource_id');
    }

    /**
     * Get top 5 most booked resources.
     */
    private function getTopBookedResources(): array
    {
        return DB::table('bookings')
            ->join('resources', 'bookings.resource_id', '=', 'resources.id')
            ->select(
                'resources.id as resource_id',
                'resources.name as resource_name',
                DB::raw('count(bookings.id) as total_bookings')
            )
            ->groupBy('resources.id', 'resources.name')
            ->orderByDesc('total_bookings')
            ->limit(5)
            ->get()
            ->toArray();
    }

    /**
     * Get monthly booking trends for the current year.
     */
    private function getMonthlyBookings(): array
    {
        return Booking::select(
                DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                DB::raw('count(*) as total_bookings')
            )
            ->whereYear('start_time', Carbon::now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();
    }

    /**
     * Get resource utilization (total booked hours) by month.
     */
    private function getResourceUtilizationMonthly(): array
    {
        return Booking::select(
                DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                DB::raw("SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) as total_booked_hours")
            )
            ->whereYear('start_time', Carbon::now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();
    }

    /**
     * Check if the user is an admin.
     */
    private function isAdmin($user): bool
    {
        return $user && $user->user_type === 'admin';
    }

    /**
     * Return a standardized unauthorized response.
     */
    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access. Only administrators can view this dashboard.'
        ], 403);
    }
}