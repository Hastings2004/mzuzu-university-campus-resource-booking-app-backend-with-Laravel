<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Only authenticated users can change their password
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'current_password' => [
                'required',
                'string',
                // Custom validation rule to check if current_password matches the authenticated user's password
                function ($attribute, $value, $fail) {
                    if (!Hash::check($value, Auth::user()->password)) {
                        $fail('The provided current password does not match your actual password.');
                    }
                },
            ],
            'password' => [
                //'required',
                'string',
                // Password rule: at least 8 characters, one uppercase, one lowercase, one number, one symbol
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(), // Check against compromised passwords
                'confirmed', // Ensures 'password_confirmation' field matches
                'different:current_password', // New password must be different from the current one
            ],
            // 'password_confirmation' is implicitly handled by 'confirmed' rule
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'password.different' => 'The new password must be different from your current password.',
        ];
    }
}