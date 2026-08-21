<?php

namespace App\Http\Requests\V2\Pass;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-service blocking (PC-1).
 *
 * A subscriber must be able to kill a lost card immediately, without a phone
 * call and without waiting for a guichet to open — the window between losing a
 * card and reporting it is the whole exposure, and every hour of it is free
 * rides on their subscription.
 */
class BlockCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `fraud` is not offered to clients: it is a staff determination,
            // and letting a customer self-label would corrupt the reporting the
            // investigations process depends on.
            'reason' => ['required', Rule::in(['lost', 'stolen'])],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez si la carte est perdue ou volée.',
        ];
    }
}
