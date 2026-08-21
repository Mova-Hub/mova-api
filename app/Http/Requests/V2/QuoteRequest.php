<?php

namespace App\Http\Requests\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mobile quote input.
 *
 * Deliberately narrower than App\Http\Requests\QuoteRequest, which serves the
 * back-office and accepts four interchangeable ways of describing a fleet plus
 * a caller-supplied distance. Every one of those is an attack surface when the
 * caller is a phone:
 *
 *  - `bus_ids[]` would let a client enumerate the fleet by probing prices;
 *  - a free `distance_km` lets anyone quote a 400 km charter as 5 km.
 *
 * So this accepts ONE fleet shape and no authoritative distance. The distance
 * is measured server-side from the waypoints (see the controller);
 * `distance_km_hint` exists only as a fallback when Google cannot route, and is
 * clamped before use.
 */
class QuoteRequest extends FormRequest
{
    /** Authorisation is the route's `auth:sanctum`; there is nothing per-record here. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Ordered route: origin … destination. Two points minimum.
            'waypoints'       => ['required', 'array', 'min:2', 'max:25'],
            'waypoints.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'waypoints.*.lng' => ['required', 'numeric', 'between:-180,180'],

            // { "hiace": 2, "coaster": 1 } — keys checked against the pricing
            // config below, so an unknown type is a 422, never a 500 from the
            // engine's InvalidArgumentException.
            'fleet'   => ['required', 'array', 'min:1'],
            'fleet.*' => ['integer', 'min:1', 'max:50'],

            'event' => ['nullable', Rule::in(array_keys(config('pricing.events')))],

            'round_trip' => ['nullable', 'boolean'],

            // Advisory only. See the class docblock.
            'distance_km_hint' => ['nullable', 'numeric', 'min:0', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $known = array_keys(config('pricing.vehicles'));

            foreach (array_keys((array) $this->input('fleet', [])) as $type) {
                if (! in_array($type, $known, true)) {
                    $validator->errors()->add('fleet', "Type de véhicule inconnu : {$type}.");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'waypoints.required' => 'L’itinéraire est requis pour estimer le tarif.',
            'waypoints.min'      => 'Indiquez au moins un départ et une destination.',
            'fleet.required'     => 'Choisissez au moins un véhicule.',
        ];
    }
}
