<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize incoming payload (camelCase + nested) to snake_case
     * expected by the validator. Also fix "envent_type"/"event_type"
     * -> "event" and translate "marriage" -> "wedding".
     */
    protected function prepareForValidation(): void
    {
        $all = $this->all();

        $get = fn(string $path, $fallback = null) => data_get($all, $path, $fallback);
        $nullIfEmpty = function ($v) {
            return (is_string($v) && trim($v) === '') ? null : $v;
        };

        // Event mapping
        $eventFromClient = $all['event']
            ?? $all['event_type']
            ?? $all['envent_type']
            ?? null;

        if ($eventFromClient === 'marriage') {
            $eventFromClient = 'wedding';
        }

        $mapped = [
            'code'            => $all['code'] ?? null,

            'trip_date'       => $all['trip_date'] ?? $all['tripDate'] ?? null,
            'from_location'   => $all['from_location'] ?? $get('route.from'),
            'to_location'     => $all['to_location'] ?? $get('route.to'),

            'passenger_name'  => $all['passenger_name'] ?? $get('passenger.name'),
            'passenger_phone' => $all['passenger_phone'] ?? $get('passenger.phone'),
            'passenger_email' => $all['passenger_email'] ?? $get('passenger.email'),

            'seats'           => $all['seats'] ?? null,
            'passengers'      => $all['passengers'] ?? null,
            'price_total'     => $all['price_total'] ?? $all['priceTotal'] ?? null,

            'status'          => $all['status'] ?? null,

            'waypoints'       => $all['waypoints'] ?? null,
            'distance_km'     => $all['distance_km'] ?? $all['distanceKm'] ?? null,

            'bus_ids'         => $all['bus_ids'] ?? $all['busIds'] ?? null,

            'event'           => $all['event'] ?? $eventFromClient,
        ];

        // Nullify empty-string values for nullable fields
        foreach (['code','passenger_email','price_total','distance_km'] as $k) {
            if (array_key_exists($k, $mapped)) {
                $mapped[$k] = $nullIfEmpty($mapped[$k]);
            }
        }

        // Ensure arrays are really arrays or null
        if (!is_array($mapped['bus_ids'] ?? null)) {
            $mapped['bus_ids'] = $nullIfEmpty($mapped['bus_ids']);
        }
        if (!is_array($mapped['waypoints'] ?? null)) {
            $mapped['waypoints'] = $nullIfEmpty($mapped['waypoints']);
        }

        // Waypoints normalization
        if (is_array($mapped['waypoints'])) {
            $mapped['waypoints'] = array_values(array_map(function ($wp) use ($nullIfEmpty) {
                return [
                    'lat'   => $wp['lat'] ?? null,
                    'lng'   => $wp['lng'] ?? null,
                    'label' => array_key_exists('label', $wp) ? $nullIfEmpty($wp['label']) : null,
                ];
            }, $mapped['waypoints']));
        }

        // Coerce bus_ids to integers (DB now uses INT primary keys)
        if (is_array($mapped['bus_ids'])) {
            $mapped['bus_ids'] = array_values(array_filter(array_map(function ($id) {
                if (is_numeric($id)) {
                    return (int) $id;
                }
                return $id;
            }, $mapped['bus_ids']), static fn($v) => $v !== null && $v !== ''));
        }

        $this->merge($mapped);
    }

    public function rules(): array
    {
        return [
            'code'            => ['nullable','string','max:32','unique:reservations,code'],
            'trip_date'       => ['required','date'],
            'from_location'   => ['required','string','max:255'],
            'to_location'     => ['required','string','max:255'],

            'passenger_name'  => ['required','string','max:120'],
            'passenger_phone' => ['required','string','max:40'],
            'passenger_email' => ['nullable','email','max:190'],

            'seats'           => ['required','integer','min:1','max:500'],
            // Head count, distinct from capacity — see the migration that added
            // the column. Nullable: a reservation opened at a counter may not
            // know it yet, and inventing a number would be worse than a null.
            'passengers'      => ['nullable','integer','min:1','max:300'],
            'price_total'     => ['nullable','numeric','min:0','max:99999999.99'],

            'status'          => ['nullable', Rule::in(['pending','confirmed','cancelled','processing'])],

            'waypoints'       => ['nullable','array','min:2'],
            // 'waypoints.*.lat'   => ['required_with:waypoints','numeric','between:-90,90'],
            // 'waypoints.*.lng'   => ['required_with:waypoints','numeric','between:-180,180'],
            // 'waypoints.*.label' => ['nullable','string','max:255'],

            'distance_km'     => ['nullable','numeric','min:0','max:100000'],

            'bus_ids'         => ['nullable','array'],
            'bus_ids.*'       => ['integer','distinct','exists:buses,id'],

            'event'           => ['required', Rule::in(['none', 'school_trip', 'university_trip', 'educational_tour', 'student_transport', 'wedding', 'funeral', 'birthday', 'baptism', 'family_meeting', 'conference', 'seminar', 'company_trip', 'business_mission', 'staff_shuttle', 'football_match', 'sports_tournament', 'concert', 'festival', 'school_competition', 'tourist_trip', 'group_excursion', 'pilgrimage', 'site_visit', 'airport_transfer', 'election_campaign', 'administrative_mission', 'official_trip', 'private_transport', 'special_event', 'simple_rental', 'church'])],
        ];
    }

    /**
     * Log normalized payload + errors on 422 for quick diagnosis.
     */
    protected function failedValidation(Validator $validator)
    {
        try {
            Log::warning('StoreReservation validation failed', [
                'normalized_payload' => $this->all(),
                'errors'  => $validator->errors()->toArray(),
                'headers' => [
                    'content_type' => $this->headers->get('Content-Type'),
                    'accept'       => $this->headers->get('Accept'),
                ],
                'query'   => $this->query->all(),
            ]);
        } catch (\Throwable $e) {}

        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
