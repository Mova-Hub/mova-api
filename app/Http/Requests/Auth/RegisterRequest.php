<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name'      => ['required', 'string', 'max:80'],
            'email'     => ['required', 'email', 'max:255', 'unique:clients,email'],
            'phone'     => ['required', 'string', 'max:13', 'unique:clients,phone'],
            'password'  => ['required', Password::min(8)->mixedCase()->numbers()->uncompromised()],
            'device_name' => ['nullable', 'string', 'max:100'],
            'fcm_token' => ['nullable', 'string'],
        ];
    }
}
