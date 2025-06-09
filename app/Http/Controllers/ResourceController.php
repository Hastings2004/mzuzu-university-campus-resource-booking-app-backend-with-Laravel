<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use Illuminate\Support\Facades\Auth;

class ResourceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    { 
        $user = Auth::user();

        // Ensure the user is authenticated

        if(!$user) {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized"
            ], 401);
        }
        // No specific role check needed here, as all authenticated users can view resources.
        $resources = Resource::all();
        
        return response()->json([
            "success"=> true,
            "resources"=>$resources
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResourceRequest $request)
    {
        //

        $user = Auth::user();
        // Ensure the user is authenticated
        if (!$user || !$user->user_type != 'admin') {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized"
            ], 401);
        }
        // Validate the request using the StoreResourceRequest
        $validatedData = $request->validated();

        $resource = Resource::create($validatedData);
        return response()->json([
            "success" => true,
            "resource" => $resource
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Resource $resource)
    {
        //
        $user = Auth::user();
        // Ensure the user is authenticated
        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized"
            ], 401);
        }
        // No specific role check needed here, as all authenticated users can view resources.
        if (!$resource) {
            return response()->json([
                "success" => false,
                "message" => "Resource not found"
            ], 404);
        }
        return response()->json([
            "success" => true,
            "resource" => $resource
        ]);

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Resource $resource)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateResourceRequest $request, Resource $resource)
    {
        //
        $user = Auth::user();
        // Ensure the user is authenticated and has the 'admin' role
        if (!$user || !$user->user_type != 'admin') {
            return response()->json([
                "success" => false,
                "message" => "Unauthorized"
            ], 401);
        }
        // Validate the request using the UpdateResourceRequest
        $validatedData = $request->validated();
        // Update the resource with the validated data
        $resource->update($validatedData);
        return response()->json([
            "success" => true,
            "resource" => $resource
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Resource $resource)
    {
        //
    }
}
