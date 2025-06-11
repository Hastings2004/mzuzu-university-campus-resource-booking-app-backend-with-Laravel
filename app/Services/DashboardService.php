<?php

namespace App\Services;

use App\Exceptions\DashboardException;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    /**
     * DashboardService constructor.
     */
    public function __construct()
    {
        // You can inject any dependencies here if needed

        
    }
    /**
     * Fetch key performance indicators (KPIs) for the dashboard.
     *
     * @return array
     * @throws DashboardException
     */
    public function getKpis(): array
    {
        try {
            $totalResources = Resource::count();
            $totalBookings = Booking::count();
            $totalUsers = User::count();

            // Calculate truly available resources: where resource status is 'available'
            // AND there are no overlapping 'approved' or 'pending' bookings
            $availableResources = Resource::where('status', 'available') // Assuming 'status' field on Resource model
                ->whereDoesntHave('bookings', function ($query) {
                    $query->where('end_time', '>', Carbon::now())
                          ->where('start_time', '<', Carbon::now()) // Currently ongoing bookings
                          ->whereIn('status', [BookingService::STATUS_APPROVED, BookingService::STATUS_PENDING, BookingService::STATUS_IN_USE]);
                })
                ->count();

            // Optionally, count resources that are active but not currently booked (future available)
            // $activeButNotCurrentlyBooked = Resource::where('is_active', true)
            //     ->whereDoesntHave('bookings', function ($query) {
            //         $query->where('end_time', '>', Carbon::now())
            //               ->where('start_time', '<', Carbon::now())
            //               ->whereIn('status', [BookingService::STATUS_APPROVED, BookingService::STATUS_PENDING, BookingService::STATUS_IN_USE]);
            //     })
            //     ->count();


            return [
                'total_resources' => $totalResources,
                'total_bookings' => $totalBookings,
                'total_users' => $totalUsers,
                'available_resources' => $availableResources, // Resources available RIGHT NOW (not booked and active)
            ];
        } catch (\Exception $e) {
            Log::error('Error fetching dashboard KPIs: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new DashboardException('Failed to retrieve key metrics.');
        }
    }

    /**
     * Get booking counts by status for a pie chart.
     *
     * @return array
     * @throws DashboardException
     */
    public function getBookingsByStatus(): array
    {
        try {
            return Booking::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error fetching bookings by status: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new DashboardException('Failed to retrieve bookings by status.');
        }
    }

    /**
     * Get resource availability overview for a chart.
     * Includes static resource statuses and dynamically calculates currently booked resources.
     *
     * @return array
     * @throws DashboardException
     */
    public function getResourceAvailabilityOverview(): array
    {
        try {
            // Fetch counts for resources based on their primary status (e.g., 'available', 'maintenance')
            $resourceAvailability = Resource::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->map(function ($item) {
                    $name = ucfirst($item->status);
                    if ($item->status === 'available') $name = 'Available (Static)'; // Differentiate from dynamic
                    if ($item->status === 'maintenance') $name = 'Under Maintenance';
                    // Add any other resource statuses you have (e.g., 'unavailable', 'decommissioned')
                    return ['name' => $name, 'count' => $item->count];
                })
                ->toArray();

            // Calculate 'Currently Booked' resources dynamically
            $currentlyBookedResourcesCount = DB::table('bookings')
                ->where('end_time', '>', Carbon::now())
                ->where('start_time', '<', Carbon::now())
                ->where('status', BookingService::STATUS_APPROVED) // Only count approved ongoing bookings
                ->distinct('resource_id')
                ->count('resource_id');

            // Add 'Currently Booked' to the resourceAvailability array, or update if a similar status exists
            $foundBookedStatus = false;
            foreach ($resourceAvailability as &$statusItem) {
                if ($statusItem['name'] === 'Currently Booked') {
                    $statusItem['count'] += $currentlyBookedResourcesCount;
                    $foundBookedStatus = true;
                    break;
                }
            }
            if (!$foundBookedStatus) {
                $resourceAvailability[] = ['name' => 'Currently Booked', 'count' => $currentlyBookedResourcesCount];
            }

            return $resourceAvailability;
        } catch (\Exception $e) {
            Log::error('Error fetching resource availability overview: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new DashboardException('Failed to retrieve resource availability overview.');
        }
    }

    /**
     * Get the top N most booked resources.
     *
     * @param int $limit
     * @return array
     * @throws DashboardException
     */
    public function getTopBookedResources(int $limit = 5): array
    {
        try {
            return DB::table('bookings')
                ->join('resources', 'bookings.resource_id', '=', 'resources.id')
                ->select('resources.id as resource_id', 'resources.name as resource_name', DB::raw('count(bookings.id) as total_bookings'))
                ->groupBy('resources.id', 'resources.name')
                ->orderByDesc('total_bookings')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error fetching top booked resources: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new DashboardException('Failed to retrieve top booked resources.');
        }
    }

    /**
     * Get monthly booking trends for the current year.
     *
     * @return array
     * @throws DashboardException
     */
    public function getMonthlyBookingTrends(): array
    {
        try {
            return Booking::select(
                    DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                    DB::raw('count(*) as total_bookings')
                )
                ->whereYear('start_time', Carbon::now()->year)
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error fetching monthly booking trends: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new DashboardException('Failed to retrieve monthly booking trends.');
        }
    }

    /**
     * Get monthly resource utilization (total booked hours) for the current year.
     *
     * @return array
     * @throws DashboardException
     */
    public function getResourceUtilizationMonthly(): array
    {
        try {
            return Booking::select(
                    DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                    DB::raw("SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) as total_booked_hours")
                )
                ->whereYear('start_time', Carbon::now()->year)
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error fetching monthly resource utilization: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new DashboardException('Failed to retrieve monthly resource utilization.');
        }
    }

    /**
     * Get all dashboard data.
     *
     * @return array
     * @throws DashboardException
     */
    public function getAllDashboardData(): array
    {
        return [
            'kpis' => $this->getKpis(),
            'bookings_by_status' => $this->getBookingsByStatus(),
            'resource_availability' => $this->getResourceAvailabilityOverview(),
            'top_booked_resources' => $this->getTopBookedResources(),
            'monthly_bookings' => $this->getMonthlyBookingTrends(),
            'resource_utilization_monthly' => $this->getResourceUtilizationMonthly(),
        ];
    }
}
