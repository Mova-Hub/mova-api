<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'first_name' => $this->first_name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'avatar_url' => $this->avatar_url,
            'license_no' => $this->license_no,
            'permit_expiration_date' => $this->permit_expiration_date?->toDateString(),
            'address'    => $this->address, // decoded array {street,quartier,arrondissement,city,department,country}
            'role'       => $this->role,    // driver|conductor|owner
            'status'     => $this->status,  // active|inactive|suspended

            // Buses: loaded in PersonController::show() for drivers/conductors
            'assigned_buses' => $this->when(
                $this->relationLoaded('busesAsDriver') || $this->relationLoaded('busesAsConductor'),
                fn () => collect()
                    ->merge($this->relationLoaded('busesAsDriver') ? $this->busesAsDriver : [])
                    ->merge($this->relationLoaded('busesAsConductor') ? $this->busesAsConductor : [])
                    ->unique('id')
                    ->values()
                    ->map(fn ($b) => [
                        'id'     => $b->id,
                        'plate'  => $b->plate,
                        'brand'  => $b->brand,
                        'model'  => $b->model,
                        'type'   => $b->type,
                        'status' => $b->status,
                        'role'   => $this->id === $b->assigned_driver_id ? 'driver' : 'conductor',
                    ])
            ),

            // Owned buses for operators
            'owned_buses' => $this->when(
                $this->role === 'owner' && $this->relationLoaded('ownedBuses'),
                fn () => $this->ownedBuses->map(fn ($b) => [
                    'id'     => $b->id,
                    'plate'  => $b->plate,
                    'brand'  => $b->brand,
                    'model'  => $b->model,
                    'type'   => $b->type,
                    'status' => $b->status,
                ])
            ),

            // Simple stats (computed when buses are loaded)
            'stats' => $this->when(
                $this->relationLoaded('busesAsDriver') || $this->relationLoaded('busesAsConductor') || $this->relationLoaded('ownedBuses'),
                fn () => $this->computeStats()
            ),

            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function computeStats(): array
    {
        $busCount = 0;

        if ($this->relationLoaded('busesAsDriver')) {
            $busCount += $this->busesAsDriver->count();
        }
        if ($this->relationLoaded('busesAsConductor')) {
            $busCount += $this->busesAsConductor->count();
        }
        if ($this->relationLoaded('ownedBuses')) {
            $busCount += $this->ownedBuses->count();
        }

        return [
            'bus_count' => $busCount,
        ];
    }
}
