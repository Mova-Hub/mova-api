<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'phone'     => ['required', 'numeric', 'exists:clients,phone'],
            'password'  => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'], // Force mobile to send a device name (e.g. "iPhone 13")
            'fcm_token' => ['nullable', 'string'], // Firebase Cloud Messaging Token
        ];
    }
}
