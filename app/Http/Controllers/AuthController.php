<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    //register a new user
    public function register(Request $request){
        $field = $request->validate([
            'first_name'=> 'required|max:255',
            'last_name'=> 'required|max:255',
            'user_type'=> 'required',
            'email'=> 'required|max:255|email|unique:users',
            'password'=> 'required|confirmed|min:6',
        ]);

        $user = User::create($field);

        $roleName = $request->input('user_type'); // 'student' or 'staff'
        $role = Role::where('name', $roleName)->first();

        if ($role) {
            $user->roles()->attach($role->id); // This is the line that performs the role assignment
        } else {
           return ['message'=>"role not found"]; // This means the role 'student' or 'staff' doesn't exist in your 'roles' table
        }

        $token = $user->createToken($request->first_name);

         
        return [
            'user'=> $user,
            'success'=> true,
            'token'=> $token->plainTextToken, // Return the token for API usage
        ];
    }

    //login a user
    public function login(Request $request){
        $request->validate([
            'email'=> 'required|max:255|email|exists:users',
            'password'=> 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if(!$user || !Hash::check($request->password, $user->password)){
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Load user with roles
        $user->load('roles');
        
        // Get the first role ID (assuming user has at least one role)
        $roleId = $user->roles->first() ? $user->roles->first()->id : null;
        
        $token = $user->createToken($user->first_name)->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role_id' => $roleId,
                'user_type' => $user->user_type, 
            ],
            'success' => true,
            'token' => $token
        ], 200);
    }

    //logout a user
    public function logout(Request $request){
        $request -> user() -> tokens() -> delete();

        return [
            'message'=> 'you have logged out'
        ];
    }
}
