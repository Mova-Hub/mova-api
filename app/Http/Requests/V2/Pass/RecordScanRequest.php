<?php

namespace App\Http\Requests\V2\Pass;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A scan reported by the app.
 *
 * Note what this request does NOT accept: a verdict. The device says what it
 * saw — a chip UID and, if it could read one, the payload — and the server
 * decides what that means. A client-supplied verdict would be a self-issued
 * travel permit.
 */
class RecordScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chip_uid' => ['nullable', 'string', 'max:64'],
            // The full card URI, when the reader got one. ~140 bytes by design;
            // 512 leaves room for a format change without inviting an upload.
            'payload' => ['nullable', 'string', 'max:512'],
            // Device-generated idempotency key. Without it a retry double-counts.
            'client_reference' => ['nullable', 'uuid'],
            'scanned_at' => ['nullable', 'date'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
