<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            '2fa_enabled'  => $this->is_2fa_enabled,
            'avatar_url'   => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'last_login_at'=> $this->last_login_at?->toIso8601String(),
            'orders_count' => $this->orders_count ?? 0,
            'created_at'   => $this->created_at->toIso8601String(),
        ];
    }
}
