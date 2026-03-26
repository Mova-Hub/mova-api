<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Emploi extends Model
{
    use HasUuids;

    protected $fillable = [
        'title', 'department', 'location', 'country', 'work_mode',
        'contract_type', 'status', 'short_desc',
        'responsibilities', 'requirements', 'benefits'
    ];

    // MAGIE LARAVEL : Cast automatiquement le JSON en Array
    protected function casts(): array
    {
        return [
            'responsibilities' => 'array',
            'requirements' => 'array',
            'benefits' => 'array',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}
