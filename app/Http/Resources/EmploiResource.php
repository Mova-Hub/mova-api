<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmploiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // On s'assure de renvoyer exactement les clés en snake_case
        // que ton frontend (job.ts) attend dans sa fonction toJob()
        return [
            'id' => $this->id,
            'title' => $this->title,
            'department' => $this->department,
            'location' => $this->location,
            'country' => $this->country,
            'work_mode' => $this->work_mode,
            'contract_type' => $this->contract_type,
            'status' => $this->status,
            'short_desc' => $this->short_desc,

            'responsibilities' => $this->responsibilities ?? [],
            'requirements' => $this->requirements ?? [],
            'benefits' => $this->benefits ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
