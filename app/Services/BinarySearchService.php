<?php

// namespace App\Services;

// use Illuminate\Support\Collection;
// use Illuminate\Support\Facades\Cache;
// use App\Models\Resource;
// use App\Models\Booking;
// use App\Models\User;
// use Illuminate\Support\Facades\Schema;
// use Illuminate\Support\Facades\Log;

// class BinarySearchService
// {
//     protected $cachePrefix = 'binary_search_index';
//     protected $cacheTtl = 3600; // 1 hour

//     public function search(string $type, string $query, string $field = 'name') // Added type to the argument list
//     {
//         $sortedData = $this->getSortedData($type, $field); // Pass type to getSortedData

//         if ($sortedData->isEmpty()) {
//             return collect();
//         }

//         $searchableItems = $this->prepareSearchableData($sortedData, $field);

//         $results = $this->binarySearch($searchableItems, $query);

//         return $this->formatResults($results, $type); // Pass type to formatResults
//     }

//     protected function getSortedData(string $type, string $field)
//     {
//         $cacheKey = "{$this->cachePrefix}_{$type}_{$field}";

//         return Cache::remember($cacheKey, $this->cacheTtl, function() use ($type, $field) {
//             // Validate the field before attempting to order by it
//             $model = null;
//             switch ($type) {
//                 case 'resources':
//                     $model = Resource::class;
//                     break;
//                 case 'bookings':
//                     $model = Booking::class;
//                     break;
//                 case 'users':
//                     $model = User::class;
//                     break;
//                 default:
//                     return collect(); // Invalid type
//             }

//             // Ensure the field actually exists on the model's table before ordering
//             // This is a crucial check to prevent 'Column not found' errors
//             if (!Schema::hasColumn((new $model)->getTable(), $field)) {
//                 // Log an error or choose a fallback field if the requested field doesn't exist
//                 Log::warning("Attempted to sort {$type} by non-existent field: {$field}. Falling back to 'id'.");
//                 $field = 'id'; // Fallback to a common, always-present field
//             }

//             $query = app($model);
//             if ($type === 'bookings') {
//                 $query = $query->with('user', 'resource'); // Eager load for bookings if needed in formatItemData
//             }

//             return $query->orderBy($field)->get();
//         });
//     }

//     protected function prepareSearchableData(Collection $data, string $field)
//     {
//         return $data->map(function ($item) use ($field) {
//             // Use optional chaining operator '?->' for robustness if $field might not exist on all items
//             return [
//                 'searchValue' => strtolower($item->$field ?? ''),
//                 'originalItem' => $item
//             ];
//         })->values();
//     }

//     /**
//      * Binary search implementation for exact and partial matches
//      */
//     protected function binarySearch(Collection $sortedItems, string $query)
//     {
//         $query = strtolower(trim($query));
//         $results = collect();

//         // Find exact matches first
//         $exactMatch = $this->binarySearchExact($sortedItems, $query);
//         if ($exactMatch !== null) {
//             $results->push($exactMatch);
//         }

//         // Find partial matches (items that start with query)
//         $partialMatches = $this->binarySearchPartial($sortedItems, $query);
//         $results = $results->merge($partialMatches);

//         return $results->unique(function ($item) {
//             // Unique by original item's ID to prevent duplicates if searched on multiple fields
//             // Assuming originalItem always has an 'id'
//             return optional($item['originalItem'])->id;
//         })->filter(); // Filter out nulls if id is missing
//     }

//     /**
//      * Binary search for exact match
//      */
//     protected function binarySearchExact(Collection $sortedItems, string $query)
//     {
//         $left = 0;
//         $right = $sortedItems->count() - 1;

//         while ($left <= $right) {
//             $mid = intval(($left + $right) / 2);
//             $midValue = $sortedItems[$mid]['searchValue'];

//             if ($midValue === $query) {
//                 return $sortedItems[$mid];
//             } elseif ($midValue < $query) {
//                 $left = $mid + 1;
//             } else {
//                 $right = $mid - 1;
//             }
//         }

//         return null;
//     }

//     /**
//      * Binary search for partial matches (items starting with query)
//      */
//     protected function binarySearchPartial(Collection $sortedItems, string $query)
//     {
//         if (empty($query)) {
//             return collect();
//         }

//         $queryLen = strlen($query);
//         $results = collect();

//         // Find the first occurrence where string starts with query
//         $firstIndex = $this->findFirstOccurrence($sortedItems, $query);

//         if ($firstIndex === -1) {
//             return collect();
//         }

//         // Collect all items that start with the query
//         for ($i = $firstIndex; $i < $sortedItems->count(); $i++) {
//             $item = $sortedItems[$i];
//             if (substr($item['searchValue'], 0, $queryLen) === $query) {
//                 $results->push($item);
//             } else {
//                 break; // Since array is sorted, we can break early
//             }
//         }

//         return $results;
//     }

//     /**
//      * Find first occurrence of items starting with query using binary search
//      */
//     protected function findFirstOccurrence(Collection $sortedItems, string $query)
//     {
//         $left = 0;
//         $right = $sortedItems->count() - 1;
//         $result = -1;
//         $queryLen = strlen($query);

//         while ($left <= $right) {
//             $mid = intval(($left + $right) / 2);
//             $midValue = $sortedItems[$mid]['searchValue'];
//             // Handle case where midValue is shorter than queryLen
//             $midPrefix = substr($midValue, 0, min(strlen($midValue), $queryLen));

//             if ($midPrefix >= $query) {
//                 if ($midPrefix === $query) {
//                     $result = $mid;
//                 }
//                 $right = $mid - 1;
//             } else {
//                 $left = $mid + 1;
//             }
//         }

//         return $result;
//     }

//     protected function formatResults(Collection $results, string $type)
//     {
//         return $results->map(function ($item) use ($type) {
//             $originalItem = $item['originalItem'];

//             return [
//                 'id' => $originalItem->id,
//                 'type' => $type, // Ensure the type is consistent with the search
//                 'data' => $this->formatItemData($originalItem, $type)
//             ];
//         });
//     }

//     protected function formatItemData($item, string $type)
//     {
//         switch ($type) {
//             case 'resources':
//                 return [
//                     'name' => $item->name ?? null,
//                     // 'type' => $item->type, // Removed, likely not a direct column
//                     'description' => $item->description ?? null,
//                     'status' => $item->status ?? null,
//                     'created_at' => $item->created_at ?? null
//                 ];
//             case 'bookings':
//                 return [
//                     'reference' => $item->reference ?? $item->id, // Default to id if no reference
//                     'user' => $item->user ? ($item->user->first_name . ' ' . $item->user->last_name) : null,
//                     'resource' => $item->resource ? $item->resource->name : null,
//                     'start_date' => $item->start_date ?? null,
//                     'end_date' => $item->end_date ?? null,
//                     'status' => $item->status ?? null,
//                     'purpose' => $item->purpose ?? null, // Assuming you might search or display purpose
//                 ];
//             case 'users':
//                 return [
//                     'first_name' => $item->first_name ?? null,
//                     'last_name' => $item->last_name ?? null, // Add last name for user's full name
//                     'email' => $item->email ?? null,
//                     'role' => $item->role ?? 'user',
//                     'created_at' => $item->created_at ?? null
//                 ];
//             default:
//                 return $item->toArray();
//         }
//     }

//     protected function getDefaultFields(string $type)
//     {
//         switch ($type) {
//             case 'resources':
//                 // Removed 'type' as it's not a column. Ensure these columns exist.
//                 return ['name', 'description', 'location'];
//             case 'bookings':
//                 // Use actual columns. 'name' or 'reference' might not exist directly.
//                 // 'purpose' and 'status' are more likely.
//                 return ['purpose', 'status'];
//             case 'users':
//                 // 'name' is not a column. Use actual columns like 'first_name', 'last_name'.
//                 return ['first_name', 'last_name', 'email'];
//             default:
//                 return ['name']; // Fallback
//         }
//     }

//     /**
//      * Multi-field search across different fields
//      */
//     public function multiFieldSearch(string $type, string $query, array $fields = [])
//     {
//         $allResults = collect();

//         // Get default fields if none are provided
//         $searchFields = empty($fields) ? $this->getDefaultFields($type) : $fields;

//         foreach ($searchFields as $field) {
//             // Call the single-field search method
//             $results = $this->search($type, $query, $field);
//             $allResults = $allResults->merge($results);
//         }

//         // Unique the results based on item ID and type to avoid duplicates
//         return $allResults->unique(function ($item) {
//             return $item['type'] . '-' . $item['id'];
//         });
//     }

//     /**
//      * Clear cache for specific type or all search caches
//      */
//     public function clearCache(string $type = null, string $field = null)
//     {
//         if ($type && $field) {
//             $cacheKey = "{$this->cachePrefix}_{$type}_{$field}";
//             Cache::forget($cacheKey);
//         } elseif ($type) {
//             // If only type is provided, clear all caches for that type across all default fields
//             $fieldsToClear = $this->getDefaultFields($type);
//             foreach ($fieldsToClear as $f) {
//                 $cacheKey = "{$this->cachePrefix}_{$type}_{$f}";
//                 Cache::forget($cacheKey);
//             }
//         } else {
//             // Clear all search cache (more robust clear)
//             $types = ['resources', 'bookings', 'users'];
//             // This is a more comprehensive list of *potential* fields across all models
//             $allPossibleFields = array_merge(
//                 $this->getDefaultFields('resources'),
//                 $this->getDefaultFields('bookings'),
//                 $this->getDefaultFields('users')
//             );
//             $allPossibleFields = array_unique($allPossibleFields); // Remove duplicates

//             foreach ($types as $t) {
//                 foreach ($allPossibleFields as $f) {
//                     $cacheKey = "{$this->cachePrefix}_{$t}_{$f}";
//                     Cache::forget($cacheKey);
//                 }
//             }
//         }
//     }
// }


namespace App\Services;

use App\Models\Resource;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache; // Assuming you use Cache for search

class BinarySearchService
{
    // Define default fields for each type
    protected $defaultSearchFields = [
        'resources' => ['name', 'description', 'location'],
        'bookings' => ['booking_reference', 'purpose'],
        'users' => ['first_name', 'last_name', 'email'],
    ];

    // Map for additional filters that can be applied directly to queries
    protected $filterableFields = [
        'resources' => ['type'], // for resource_type
        'bookings' => ['start_time', 'end_time', 'user_id'],
        'users' => ['id'], // for user_id on user search
    ];

    public function search(string $type, string $query, string $field): Collection
    {
        // This method is for single field search, less relevant for global search
        // You might refactor the frontend to only use globalSearch or multiFieldSearch
        return $this->multiFieldSearch($type, $query, [$field]);
    }

    /**
     * Performs a search across multiple fields for a given type.
     *
     * @param string $type The type of entity to search ('resources', 'bookings', 'users').
     * @param string $query The search keyword.
     * @param array $fields Specific fields to search within. If empty, uses default fields.
     * @param array $additionalFilters Additional filters to apply to the query (e.g., 'type' for resources, 'start_time' for bookings).
     * @return Collection
     */
    public function multiFieldSearch(string $type, string $query, array $additionalFilters = []): Collection
    {
        $model = null;
        $searchFields = [];
        $eagerLoads = []; // Relationships to load for results

        switch ($type) {
            case 'resources':
                $model = new Resource();
                $searchFields = $this->defaultSearchFields['resources'];
                break;
            case 'bookings':
                $model = new Booking();
                $searchFields = $this->defaultSearchFields['bookings'];
                $eagerLoads = ['resource', 'user']; // Load related resource and user for frontend display
                break;
            case 'users':
                $model = new User();
                $searchFields = $this->defaultSearchFields['users'];
                break;
            default:
                return collect(); // Return empty collection for unsupported types
        }

        // Build the query
        $results = $model->newQuery();

        // Apply keyword search across specified fields
        $results->where(function (Builder $q) use ($searchFields, $query) {
            foreach ($searchFields as $field) {
                $q->orWhere($field, 'like', '%' . $query . '%');
            }
        });

        // Apply additional filters
        foreach ($additionalFilters as $filterKey => $filterValue) {
            if (isset($this->filterableFields[$type]) && in_array($filterKey, $this->filterableFields[$type])) {
                if ($filterKey === 'start_time') {
                    $results->where('start_time', '>=', $filterValue);
                } elseif ($filterKey === 'end_time') {
                    $results->where('end_time', '<=', $filterValue);
                } elseif ($filterKey === 'user_id') {
                    $results->where('user_id', $filterValue);
                } elseif ($filterKey === 'type' && $type === 'resources') { // For resource type filter
                    $results->where('type', $filterValue);
                } elseif ($filterKey === 'id' && $type === 'users') { // For user ID filter on users
                    $results->where('id', $filterValue);
                }
                // Add more specific filter logic here as needed
            }
        }

        // Eager load relationships if any
        if (!empty($eagerLoads)) {
            $results->with($eagerLoads);
        }

        // Get results and add 'type' discriminator for frontend
        return $results->get()->map(function ($item) use ($type) {
            $item->type = $type; // Add a 'type' property to each item
            return $item;
        });
    }

    public function clearCache(?string $type = null, ?string $field = null): void
    {
        if ($type && $field) {
            Cache::forget("search_{$type}_{$field}");
        } elseif ($type) {
            // Clear all caches for a specific type
            foreach ($this->defaultSearchFields[$type] as $field) {
                Cache::forget("search_{$type}_{$field}");
            }
        } else {
            // Clear all search caches
            foreach ($this->defaultSearchFields as $t => $fields) {
                foreach ($fields as $f) {
                    Cache::forget("search_{$t}_{$f}");
                }
            }
        }
    }
}