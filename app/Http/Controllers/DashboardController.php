<?php

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
}