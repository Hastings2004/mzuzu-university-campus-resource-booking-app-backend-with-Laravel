<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\BinarySearchService;
use App\Http\Requests\BinarySearchRequest; // Assuming this Request handles validation correctly
use Illuminate\Support\Facades\Auth; // Import Auth facade

class BinarySearchController extends Controller
{
    protected $searchService;

    public function __construct(BinarySearchService $searchService)
    {
        $this->searchService = $searchService;
        // Optionally apply middleware if you want to restrict search access
        // $this->middleware('auth:sanctum');
    }

    /**
     * Single type search (e.g., search resources by name)
     * Expects: ?type=resources&query=laptop&field=name
     * NOTE: Frontend's global search will primarily hit `globalSearch` now.
     */
    public function search(BinarySearchRequest $request)
    {
        $type = $request->input('type');
        $query = $request->input('query');
        $field = $request->input('field', 'name');

        $results = $this->searchService->search($type, $query, $field);

        return response()->json([
            'query' => $query,
            'type' => $type,
            'field' => $field,
            'count' => $results->count(),
            'results' => $results->values()
        ]);
    }

    /**
     * Multi-field search within a single type (e.g., search users by first_name OR email)
     * Expects: ?type=users&query=john&fields[]=first_name&fields[]=email
     * This method is called by `globalSearch` internally.
     */
    public function multiFieldSearch(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1',
            'fields' => 'nullable|array',
            'fields.*' => 'string'
        ]);

        $type = $request->input('type'); // Passed internally from globalSearch or directly as query param
        $query = $request->input('query');
        $fields = $request->input('fields', []);

        // The type hinting for multiFieldSearch expects string $query, array $fields.
        $results = $this->searchService->multiFieldSearch($type, $query, $fields);

        return response()->json([
            'query' => $query,
            'type' => $type,
            'fields' => $fields,
            'count' => $results->count(),
            'results' => $results->values()
        ]);
    }

    /**
     * Global search across all predefined types (resources, bookings, users)
     * Expects: ?query=keyword&resource_type=...&start_time=...&end_time=...&user_id=...
     */
    public function globalSearch(Request $request)
    {
        $user = Auth::user(); // Get the authenticated user
        
        $request->validate([
            'query' => 'required|string|min:1',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date|after_or_equal:start_time',
            'user_id' => 'nullable|integer|exists:users,id', 
        ]);

        $query = $request->input('query');
        $allResults = collect();

        // Determine which types to search based on user role
        $typesToSearch = ['resources', 'bookings'];
        if ($user && ($user->user_type === 'admin' || $user->role?->name === 'admin')) {
            $typesToSearch[] = 'users'; // Add 'users' only for admins
        }

        // Extract additional filters from the request
        $resourceTypeFilter = $request->input('resource_type');
        $startTimeFilter = $request->input('start_time');
        $endTimeFilter = $request->input('end_time');
        $userIdFilter = $request->input('user_id');

        foreach ($typesToSearch as $type) {
            // Pass the extracted filters to the service if relevant
            $filters = [];
            if ($type === 'resources' && $resourceTypeFilter) {
                $filters['type'] = $resourceTypeFilter;
            }
            if ($type === 'bookings') {
                if ($startTimeFilter) $filters['start_time'] = $startTimeFilter;
                if ($endTimeFilter) $filters['end_time'] = $endTimeFilter;
                if ($userIdFilter) $filters['user_id'] = $userIdFilter;
            }
            if ($type === 'users' && $userIdFilter) { // For users, if user_id filter is passed
                 $filters['id'] = $userIdFilter; // Filter users by ID
            }


            // Call multiFieldSearch for each type, and pass specific filters
            // Assuming multiFieldSearch handles default fields if not provided
            $results = $this->searchService->multiFieldSearch($type, $query, $filters);
            $allResults = $allResults->merge($results);
        }

        $uniqueResults = $allResults->unique(function ($item) {
            return ($item['type'] ?? 'unknown') . '-' . ($item['id'] ?? uniqid()); // Handle potential missing type/id for robustness
        });

        return response()->json([
            'query' => $query,
            'types_searched' => $typesToSearch,
            'total_count' => $uniqueResults->count(),
            'results_by_type' => [
                'resources' => $uniqueResults->where('type', 'resources')->values(),
                'bookings' => $uniqueResults->where('type', 'bookings')->values(),
                'users' => $uniqueResults->where('type', 'users')->values() // Will be empty for non-admins
            ]
        ]);
    }

    /**
     * Clear search cache
     */
    public function clearCache(Request $request)
    {
        $type = $request->input('type');
        $field = $request->input('field');

        $this->searchService->clearCache($type, $field);

        return response()->json([
            'message' => 'Search cache cleared successfully'
        ]);
    }
}