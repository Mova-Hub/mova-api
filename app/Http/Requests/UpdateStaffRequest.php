<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
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
        $id = $this->route('staff'); // using {user} binding

        return [
            'name'   => ['sometimes','required','string','max:255'],
            'email'  => ['sometimes','nullable','email','max:255', Rule::unique('users','email')->ignore($id)],
            'phone'  => ['sometimes','nullable','string','max:50', Rule::unique('users','phone')->ignore($id)],
            'avatar_url' => ['sometimes','nullable'],
            'license_no' => ['sometimes','nullable','string','max:100'],
            'password'   => ['sometimes','nullable','string','min:8'],
            'role'       => ['sometimes','required', Rule::in(\App\Models\User::LOGIN_ROLES)],
            'status'     => ['sometimes','required', Rule::in(['active','inactive','suspended'])],
        ];
    }
}
