<?php

namespace App\Http\Requests\V2\Pass;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activation by tap (chip UID) or by the serial printed on the card.
 *
 * The serial path is PA-2 and is not optional: on iPhone, a user who dismisses
 * Apple's scan sheet has no other way in, and plenty of Android phones have no
 * NFC at all.
 */
class ActivateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route guard is auth:sanctum; ownership is decided in CardService.
    }

    public function rules(): array
    {
        return [
            'chip_uid' => ['nullable', 'string', 'max:64', 'required_without:printed_serial'],
            // Bounded so the lookup can never be handed a megabyte to compare.
            'printed_serial' => ['nullable', 'string', 'max:32', 'required_without:chip_uid'],
        ];
    }

    public function messages(): array
    {
        return [
            'chip_uid.required_without' => 'Approchez votre carte ou saisissez son numéro.',
            'printed_serial.required_without' => 'Approchez votre carte ou saisissez son numéro.',
        ];
    }
}
