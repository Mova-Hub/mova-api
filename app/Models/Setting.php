<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One configurable value.
 *
 * Almost never touched directly — go through App\Domain\Settings\SettingsRepository
 * (or the `Settings` facade), which caches, falls back to config, and casts.
 * Reading this model straight bypasses the config fallback, which is how you
 * get a null where a default was expected.
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'is_secret', 'updated_by'];

    protected $casts = [
        // Always JSON, so `false` survives the round trip as a boolean rather
        // than coming back as the string "false" — which is truthy, and which
        // would one day enable something that was switched off.
        'value' => 'array',
        'is_secret' => 'boolean',
    ];

    /**
     * Secrets never leave the server in a read response.
     *
     * Belt and braces with the Redactor's key-name list: that catches
     * `api_key` and `secret` by substring, this catches the ones nobody
     * thought to name obviously.
     */
    protected $hidden = ['value'];
}
