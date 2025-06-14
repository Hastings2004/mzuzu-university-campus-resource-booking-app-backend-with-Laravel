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
            "name" => ['required', 'string', 'min:3', 'max:100'], 
            "description" => ['required', 'string', 'min:5', 'max:500'], 
            "location" => ['required', 'string', 'min:3', 'max:100'],
            "capacity" => ['required', 'integer', 'min:1'], 
            "category" => ['required', 'string', 'in:classrooms,ict_labs,science_labs,auditorium,sports,cars'], 
            "status" => ['required', 'string', 'in:available,unavailable'], 
            "image" => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'] 
        
        ];
    }
}
