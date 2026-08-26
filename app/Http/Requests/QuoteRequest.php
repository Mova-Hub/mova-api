<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $eventKeys = array_keys(config('pricing.events'));
        $vehicleKeys = array_keys(config('pricing.vehicles'));

        return [
            // Priority: bus_ids > vehicles_map > vehicles > legacy vehicle_type+buses

            // A) Bus IDs
            'bus_ids'        => ['sometimes','array','min:1','max:100'],
            'bus_ids.*'      => ['integer','distinct'],

            // B) Compact map: { "hiace": 3, "coaster": 2 }
            'vehicles_map'   => ['sometimes','array','min:1'],
            // keys must be known vehicle types; values are positive integers
            'vehicles_map.*' => ['integer','min:1'],

            // C) Flat array (fallback): ["hiace","coaster",...]
            'vehicles'       => ['sometimes','array','min:1','max:100'],
            'vehicles.*'     => [Rule::in($vehicleKeys)],

            // D) Legacy (final fallback)
            'vehicle_type'   => ['sometimes',
                                'required_without_all:bus_ids,vehicles_map,vehicles',
                                Rule::in($vehicleKeys)],
            'buses'          => ['sometimes','integer','min:1','max:100'],

            // Common
            'distance_km'    => ['required','numeric','min:0'],
            'event'          => ['nullable', Rule::in($eventKeys)],
            'when'           => ['nullable','date'],

            /*
             * A return leg doubles the billed distance.
             *
             * The mobile path has priced round trips since launch
             * (TripQuoteService: 'a return leg is the same road driven twice').
             * This endpoint had no such concept, so the back-office quoted a
             * round trip at the one-way price — and the reservation it created
             * was billed at roughly half what the app had already quoted the
             * same customer.
             */
            'round_trip'     => ['sometimes','boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_type.required_without_all' =>
                'Provide bus_ids[] OR vehicles_map{} OR vehicles[] OR legacy vehicle_type+buses.',
        ];
    }

}
