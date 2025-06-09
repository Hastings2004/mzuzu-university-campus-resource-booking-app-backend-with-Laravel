<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ResourceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $resources = Resource::all();

        return response()->json([
            'success' => true,
            'data' => $resources,
            'message' => 'Resources retrieved successfully'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (!$this->isAdmin($user)) {
            return $this->unauthorizedResponse();
        }

        $validatedData = $request->validated();
        $resource = Resource::create($validatedData);

        return response()->json([
            'success' => true,
            'data' => $resource,
            'message' => 'Resource created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Resource $resource): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $resource,
            'message' => 'Resource retrieved successfully'
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateResourceRequest $request, Resource $resource): JsonResponse
    {
        $user = Auth::user();

        if (!$this->isAdmin($user)) {
            return $this->unauthorizedResponse();
        }

        $validatedData = $request->validated();
        $resource->update($validatedData);

        return response()->json([
            'success' => true,
            'data' => $resource,
            'message' => 'Resource updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $user = Auth::user();

        if (!$this->isAdmin($user)) {
            return $this->unauthorizedResponse();
        }

        $resource->delete();

        return response()->json([
            'success' => true,
            'message' => 'Resource deleted successfully'
        ]);
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
            'message' => 'Unauthorized access'
        ], 401);
    }
}