<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'first_name' => $this->first_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'license_no' => $this->license_no,
            'role'   => $this->role,   // agent|admin
            'status' => $this->status, // active|inactive|suspended

            /*
             * These two were missing, and the back-office had columns for both.
             *
             * `staff.ts` maps them and renders a 2FA shield and a "dernière
             * connexion" cell — which showed `false` and `—` on every row
             * because nothing here ever emitted them. Both columns exist on
             * `users` (the 2FA one was added 2026-08-23); this was purely a
             * Resource omission.
             *
             * `last_login_at` matters more than it looks: it is the first thing
             * anyone asks about a suspicious account, and until now the schema
             * could answer it and the API could not.
             */
            'is_2fa_enabled' => (bool) $this->is_2fa_enabled,
            'last_login_at' => $this->last_login_at?->toIso8601String(),

            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
