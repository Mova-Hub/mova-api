<?php

namespace App\Http\Requests\V2\Pass;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // By CODE, not id. The code is the stable machine key, so a plan can
            // be renamed or reordered without breaking a released app build.
            'plan_code' => ['required', 'string', 'exists:pass_plans,code'],
            'auto_renew' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_code.exists' => 'Cette formule n’existe pas.',
        ];
    }
}
