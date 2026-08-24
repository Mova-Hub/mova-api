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

            /*
             * Suspension state, which was missing.
             *
             * `ClientController@block` writes both columns and the back-office
             * reads them to decide whether to show Suspendre or Réactiver — so
             * without these the button showed "Suspendre" on every row,
             * including for accounts already suspended. `is_blocked` is derived
             * here rather than in the client, so the API and the UI cannot
             * disagree about what "blocked" means.
             */
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'blocked_reason' => $this->blocked_reason,
            'is_blocked' => $this->blocked_at !== null,

            // `?->` — the only line here that lacked it. A row with a null
            // created_at threw a 500 for the whole list.
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
