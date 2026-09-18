<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file a client attached to a support message.
 *
 * On the `local` disk, which is not web reachable, and reachable only through
 * `URL::temporarySignedRoute`. See the migration for why this differs from every
 * other upload in the codebase.
 */
class SupportAttachment extends Model
{
    protected $fillable = [
        'support_message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }

    /**
     * A filename safe to put in a `Content-Disposition` header.
     *
     * The stored name came from a phone and is not trusted. Stripping everything
     * but a conservative set keeps a quote or a newline out of the header, which
     * is response splitting, and keeps a path separator out of a name a browser
     * will save to disk.
     */
    public function downloadName(): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $this->original_name) ?: 'piece-jointe';

        return mb_substr(ltrim($safe, '.'), 0, 100) ?: 'piece-jointe';
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
