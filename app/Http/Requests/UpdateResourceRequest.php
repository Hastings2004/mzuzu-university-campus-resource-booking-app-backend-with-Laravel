<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResourceRequest extends FormRequest
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
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //validation rules for updating a resource
            "name" => ['sometimes', 'min:10', 'max:100', 'alpha'],
            "description" => ['sometimes', 'min:15', 'max:255'],
            "location" => ['sometimes', 'min:10', 'max:100'],
            "capacity" => ['sometimes', 'min:10', 'numeric'],
            "category"=> ['required'],
            "status" => ['sometimes', 'in:available,unavailable'],
            "image" => ['sometimes', 'min:10', 'string']

        ];
    }
}
