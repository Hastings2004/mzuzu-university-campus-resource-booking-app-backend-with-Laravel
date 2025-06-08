<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

class BinarySearchRequest extends FormRequest
{
    public function authorize()
    {
        return Auth::check(); // Ensure the user is authenticated
    }

    public function rules()
    {
        return [
            // 'type' => 'required|in:resources,bookings,users',
            'query' => 'required|string|min:1|max:255',
            'field' => 'nullable|string|in:name,type,description,reference,email'
        ];
    }

    public function messages()
    {
        return [
            // 'type.required' => 'Search type is required',
            // 'type.in' => 'Search type must be resources, bookings, or users',
            'query.required' => 'Search query is required',
            'query.min' => 'Search query must be at least 1 character',
            'field.in' => 'Invalid search field specified'
        ];
    }
}
