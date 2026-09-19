<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusRequest extends FormRequest
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
        $id = $this->route('bus'); // {bus} route model binding (uuid)

        return [
            'plate'   => ['sometimes','required','string','max:50', Rule::unique('buses','plate')->ignore($id)],
            'capacity'=> ['sometimes','required','integer','min:1','max:200'],
            'name'    => ['sometimes','nullable','string','max:100'],
            'type'    => ['sometimes','nullable', Rule::in(['hiace', 'coaster'])],
            'status'  => ['sometimes','required', Rule::in(['active','maintenance','inactive'])],
            'model'   => ['sometimes','nullable','string','max:100'],
            'year'    => ['sometimes','nullable','integer','min:1970'],
            'mileage_km' => ['sometimes','nullable','integer','min:0'],
            'last_service_date' => ['sometimes','nullable','date'],

            // Fillable on the model, validated nowhere, therefore silently
            // dropped by `validated()` on every edit. See StoreBusRequest.
            'brand'                   => ['sometimes','nullable','string','max:100'],
            'energy_type'             => ['sometimes','nullable','string','max:50'],
            'first_registration_year' => ['sometimes','nullable','integer','min:1970'],
            'chassis_number'          => ['sometimes','nullable','string','max:100'],
            'insurance_provider'       => ['sometimes','nullable','string','max:150'],
            'insurance_policy_number'  => ['sometimes','nullable','string','max:100'],
            'insurance_valid_until'    => ['sometimes','nullable','date','after_or_equal:last_service_date'],

            'operator_id' => [
                'sometimes','nullable',
                Rule::exists('users','id')->where(fn($q)=>$q->whereIn('role',['owner','admin'])),
            ],
            'assigned_driver_id' => [
                'sometimes','nullable',
                Rule::exists('users','id')->where(fn($q)=>$q->where('role','driver')),
            ],
            'assigned_conductor_id' => [
                'sometimes','nullable',
                Rule::exists('users','id')->where(fn($q)=>$q->where('role','conductor')),
            ],
        ];
    }
}
