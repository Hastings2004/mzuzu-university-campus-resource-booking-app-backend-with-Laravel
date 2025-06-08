<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\BinarySearchService;
use App\Http\Requests\BinarySearchRequest; // Assuming this Request handles validation correctly

class BinarySearchController extends Controller
{
    protected $searchService;

    public function __construct(BinarySearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Single type search (e.g., search resources by name)
     * Expects: ?type=resources&query=laptop&field=name
     */
    public function search(BinarySearchRequest $request)
    {
        // BinarySearchRequest should validate 'type', 'query', and 'field'
        $type = $request->input('type'); // NOW UNCOMMENTED AND USED!
        $query = $request->input('query');
        $field = $request->input('field', 'name'); // Default to 'name' if not provided

        // Correctly call the service method with all required arguments
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
     */
    public function multiFieldSearch(Request $request)
    {
        // We'll use Request validation here, but you could create a specific FormRequest for this too.
        $request->validate([
            //'type' => 'required|string|in:resources,bookings,users', // Type is essential here
            'query' => 'required|string|min:1',
            'fields' => 'nullable|array',
            'fields.*' => 'string' // Ensure each field name in the array is a string
        ]);

        $type = $request->input('type');
        $query = $request->input('query');
        $fields = $request->input('fields', []);

        // The type hinting for multiFieldSearch expects string $query, array $fields.
        // The error you had previously was likely from a variable holding an array being passed to $query.
        // Now, we're ensuring $query is a string from the validation.
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
     * Expects: ?query=keyword
     */
    public function globalSearch(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1',
            // No 'types' parameter validation needed here, as it's hardcoded to all
        ]);

        $query = $request->input('query');
        // Correctly define the array of types to loop through
        $types = ['resources', 'bookings', 'users']; // Fixed this line

        $allResults = collect();

        foreach ($types as $type) {
            // Call multiFieldSearch for each type, letting it use default fields for that type
            $results = $this->searchService->multiFieldSearch($type, $query);
            $allResults = $allResults->merge($results);
        }

        // It's often useful to ensure unique results across types if IDs could overlap
        // However, your formatResults includes 'type' in the formatted data,
        // so you might want to unique based on a combination like 'type' and 'id'.
        // For simplicity, unique by the internal 'originalItem.id' from BinarySearchService for now.
        // Assuming your formatResults correctly adds the 'type' field to the root of the formatted item.
        $uniqueResults = $allResults->unique(function ($item) {
             return $item['type'] . '-' . $item['id'];
        });

        return response()->json([
            'query' => $query,
            'types_searched' => $types, // Renamed for clarity
            'total_count' => $uniqueResults->count(),
            'results_by_type' => [
                'resources' => $uniqueResults->where('type', 'resources')->values(),
                'bookings' => $uniqueResults->where('type', 'bookings')->values(),
                'users' => $uniqueResults->where('type', 'users')->values()
            ]
        ]);
    }

    /**
     * Clear search cache
     * Expects: ?type=resources&field=name OR no params to clear all
     */
    public function clearCache(Request $request)
    {
        $type = $request->input('type');
        $field = $request->input('field');

        // Pass nulls if not provided to clear all specific caches
        $this->searchService->clearCache($type, $field);

        return response()->json([
            'message' => 'Search cache cleared successfully'
        ]);
    }
}