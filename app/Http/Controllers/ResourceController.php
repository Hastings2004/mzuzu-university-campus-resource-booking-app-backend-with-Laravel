<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Services\ResourceService; // Import the new service
use App\Exceptions\ResourceException; // Import the custom exception
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ResourceController extends Controller
{
    protected $resourceService;

    public function __construct(ResourceService $resourceService)
    {
        $this->resourceService = $resourceService;
        // Apply middleware here if you want to protect all resource routes.
        // For example, only admins can manage resources.
        // $this->middleware('auth:sanctum')->except(['index', 'show']); // Allow guests to view, but require auth for others
        // $this->middleware('admin')->only(['store', 'update', 'destroy']); // Custom admin middleware
    }

    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Authorization check: You might want to allow all authenticated users to view resources,
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "Unauthenticated."
            ], 401); // Unauthorized
        }
        // If you want to restrict access to admins only, uncomment the following lines:
        try {
            $resources = $this->resourceService->getAllResources();
            return response()->json([
                "success" => true,
                "resources" => $resources
            ]);
        } catch (ResourceException $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 500); // Use specific code or default to 500
        } catch (\Exception $e) {
            Log::error('ResourceController@index failed: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "An unexpected error occurred while fetching resources."
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreResourceRequest $request
     * @return JsonResponse
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        $user = Auth::user();
        // Authorization check: Only admins can create resources
        if (!$user || ($user->user_type !== 'admin' && $user->role?->name !== 'admin')) {
             return response()->json([
                "success" => false,
                "message" => "Unauthorized to create resources."
            ], 403); // Use 403 Forbidden for authorization issues
        }

        try {
            $validatedData = $request->validated();
            $resource = $this->resourceService->createResource($validatedData);
            return response()->json([
                "success" => true,
                "message" => "Resource created successfully.",
                "resource" => $resource
            ], 201); // 201 Created status
        } catch (ResourceException $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 400); // Bad Request for validation-like errors
        } catch (\Exception $e) {
            Log::error('ResourceController@store failed: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "An unexpected error occurred while creating the resource."
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param Resource $resource
     * @return JsonResponse
     */
    public function show(Resource $resource): JsonResponse
    {
        $user = Auth::user();
        // Authorization check: You might want to allow all authenticated users to view resources,
        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "Unauthenticated."
            ], 401);
        }

        try {
            // If you want to restrict access to admins only, uncomment the following lines:
            $foundResource = $this->resourceService->getResourceById($resource->id);
            return response()->json([
                "success" => true,
                "resource" => $foundResource
            ]);
        } catch (ResourceException $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 404); // Not Found if resource not found
        } catch (\Exception $e) {
            Log::error('ResourceController@show failed: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "An unexpected error occurred while fetching the resource."
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateResourceRequest $request
     * @param Resource $resource
     * @return JsonResponse
     */
    public function update(UpdateResourceRequest $request, Resource $resource): JsonResponse
    {
        $user = Auth::user();
        // Authorization check: Only admins can update resources
        if (!$user || ($user->user_type !== 'admin' && $user->role?->name !== 'admin')) {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized to update resources."
            ], 403);
        }

        try {
            $validatedData = $request->validated();
            $updatedResource = $this->resourceService->updateResource($resource, $validatedData);
            return response()->json([
                "success" => true,
                "message" => "Resource updated successfully.",
                "resource" => $updatedResource
            ]);
        } catch (ResourceException $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 400);
        } catch (\Exception $e) {
            Log::error('ResourceController@update failed: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "An unexpected error occurred while updating the resource."
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Resource $resource
     * @return JsonResponse
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $user = Auth::user();
        // Authorization check: Only admins can delete resources
        if (!$user || ($user->user_type !== 'admin' && $user->role?->name !== 'admin')) {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized to delete resources."
            ], 403);
        }

        try {
            $this->resourceService->deleteResource($resource);
            return response()->json([
                "success" => true,
                "message" => "Resource deleted successfully."
            ], 200); // 200 OK or 204 No Content
        } catch (ResourceException $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 400);
        } catch (\Exception $e) {
            Log::error('ResourceController@destroy failed: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "An unexpected error occurred while deleting the resource."
            ], 500);
        }
    }

}
