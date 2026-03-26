<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidate extends Model
{
    use HasUuids;

    protected $fillable = [
        'emploi_id', 'first_name', 'last_name', 'email', 'phone',
        'resume_path', 'cover_letter_path', 'status', 'notes'
    ];

    public function emploi(): BelongsTo
    {
        return $this->belongsTo(Emploi::class);
    }
}
