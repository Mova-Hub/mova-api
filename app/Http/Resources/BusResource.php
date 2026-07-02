<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => (string) $this->id,
            'plate'    => $this->plate,
            'brand'    => $this->brand,
            'capacity' => (int) $this->capacity,
            'name'     => $this->name,
            'type'     => $this->type,
            'status'   => $this->status,
            'model'    => $this->model,
            'year'     => $this->year,
            'energy_type'             => $this->energy_type,
            'first_registration_year' => $this->first_registration_year,
            'chassis_number'          => $this->chassis_number,
            'mileage_km'              => $this->mileage_km,
            'last_service_date'       => $this->last_service_date?->toDateString(),
            'insurance_provider'      => $this->insurance_provider,
            'insurance_policy_number' => $this->insurance_policy_number,
            'insurance_valid_until'   => $this->insurance_valid_until?->toDateString(),

            'operator_id'          => $this->operator_id,
            'assigned_driver_id'   => $this->assigned_driver_id,
            'assigned_conductor_id'=> $this->assigned_conductor_id,

            'operator' => $this->whenLoaded('operator', fn() => [
                'id'    => $this->operator->id,
                'name'  => $this->operator->name,
                'phone' => $this->operator->phone ?? null,
            ]),
            'driver' => $this->whenLoaded('driver', fn() => [
                'id'    => $this->driver->id,
                'name'  => $this->driver->name,
                'phone' => $this->driver->phone ?? null,
            ]),
            'conductor' => $this->whenLoaded('conductor', fn() => [
                'id'    => $this->conductor->id,
                'name'  => $this->conductor->name,
                'phone' => $this->conductor->phone ?? null,
            ]),
            'documents' => $this->whenLoaded('documents', fn() =>
                $this->documents->map(fn($d) => [
                    'id'          => $d->id,
                    'name'        => $d->name,
                    'type'        => $d->type,
                    'file_url'    => $d->file_path ? Storage::disk('public')->url($d->file_path) : null,
                    'mime_type'   => $d->mime_type,
                    'size_kb'     => $d->size_kb,
                    'expires_at'  => $d->expires_at?->toDateString(),
                    'uploaded_by' => $d->uploaded_by,
                    'created_at'  => $d->created_at?->toIso8601String(),
                ])
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
