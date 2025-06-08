<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\UpdatePasswordRequest;

class UserController extends Controller
{
    public function index()
    {
        // ... authentication and authorization checks ...

        // If authorized, fetch all users
        $users = User::all();

        return response()->json([
            "success" => true,
            "users" => $users // <--- This is the expected structure
        ]);
    }

    public function getProfile(Request $request)
    {
        try {
            // Ensure the user is authenticated
            if (!Auth::check()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $user = Auth::user();

            // Return the authenticated user's data
            return response()->json([
                'success' => true,
                'user' => $user // Always return a single user object
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user profile', [
                'user_id' => Auth::id(), // Log the user ID if available
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(), // Add stack trace for better debugging
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load profile. Please try again.'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        // Ensure the user is authenticated
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check if the authenticated user is trying to update their own profile
        if (Auth::id() !== (int)$id) {
            return response()->json(['message' => 'Unauthorized to update this profile.'], 403);
        }

        $authenticatedUser = Auth::user();

            // Ensure $authenticatedUser is an instance of App\Models\User
            if (!$authenticatedUser || !($authenticatedUser instanceof \App\Models\User)) {
                return response()->json(['message' => 'Authentication required.'], 401);
            }

            try {
                $rules = [
                    'first_name' => ['sometimes', 'string', 'max:255'],
                    'last_name' => ['sometimes', 'string', 'max:255'],
                    'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $authenticatedUser->id],
                    'password' => ['nullable', 'string', 'min:8', 'confirmed'],
                ];

                $validatedData = $request->validate($rules);

                if (isset($validatedData['password'])) {
                    $validatedData['password'] = Hash::make($validatedData['password']);
                }

                $authenticatedUser->fill($validatedData);
                $authenticatedUser->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Profile updated successfully!',
                    'user' => $authenticatedUser,
                ], 200);

            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation Error',
                    'errors' => $e->errors(),
                ], 422);
            } catch (\Exception $e) {
                Log::error('Profile update failed: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred during profile update.',
                ], 500);
            }
    }


    /**
     * Handle the user password change request.
     *
     * @param  \App\Http\Requests\UpdatePasswordRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(UpdatePasswordRequest $request)
    {
        // The validated data already contains the hashed new password (if you put the hashing in the request)
        // Or, if not, hash it here:
        $newPassword = $request->input('password');

        try {
            $user = Auth::user(); // Get the authenticated user

            if (!$user || !($user instanceof User)) {
                return response()->json(['message' => 'Authentication required.'], 401);
            }

            // Update the user's password
            $user->password = Hash::make($newPassword);
            $user->save(); // Save the changes to the database

            return response()->json(['message' => 'Password updated successfully!'], 200);

        } catch (\Exception $e) {
            Log::error('Password change failed for user ' . Auth::id() . ': ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'An unexpected error occurred while changing password.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
