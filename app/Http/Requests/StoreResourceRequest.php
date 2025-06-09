<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

class StoreResourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if the user is authenticated and has the 'admin' role
        $user = $this->user();
        if ($user && $user->hasRole('admin')) {
            return true;
        }
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            "name"=>['required', 'min:10', 'max:100'],
            "description" => ['required', 'min:15', 'max:255'],
            "location" => ['required', 'min:10', 'max:100'],
            "capacity" => ['required', 'min:10', 'number'],
            "status" => ['required'],
            "image" => ['min:10']  
        ];
    }
}
