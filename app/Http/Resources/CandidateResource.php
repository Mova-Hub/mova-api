<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'emploi_id' => $this->emploi_id,
            // On charge les infos de l'offre d'emploi si elles sont demandées
            'emploi' => new EmploiResource($this->whenLoaded('emploi')),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,

            // Génération des liens de téléchargement sécurisés
            'resume_url' => $this->resume_path ? url(Storage::url($this->resume_path)) : null,
            'cover_letter_url' => $this->cover_letter_path ? url(Storage::url($this->cover_letter_path)) : null,

            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
