<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */

    public function rules(): array
    {
        return [
            'name'   => ['required','string','max:255'],
            'email'  => ['nullable','email','max:255','unique:users,email'],
            'phone'  => ['nullable','string','max:50','unique:users,phone'],
            'avatar_url' => ['nullable','url'],
            'license_no' => ['nullable','string','max:100'],
            'password'   => ['nullable','string','min:8'], // can be null if you invite later
            // Every role that can log in — back-office AND field. Creating a
            // coordinator or a controller is a back-office job; what those
            // accounts may then reach is decided by the `field` middleware, not
            // by this list. Fleet records are `PersonController`'s.
            'role'       => ['required', Rule::in(\App\Models\User::LOGIN_ROLES)],
            'status'     => ['nullable', Rule::in(['active','inactive','suspended'])],
        ];
    }
}
