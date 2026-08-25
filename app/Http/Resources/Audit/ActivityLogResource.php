<?php

namespace App\Http\Resources\Audit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'action' => $this->action,
            'actor' => [
                // class_basename so the UI shows "User" / "Client" rather than
                // a fully-qualified namespace nobody reads.
                'type' => $this->actor_type ? class_basename($this->actor_type) : null,
                'id' => $this->actor_id,
                'label' => $this->actor_label ?? 'Système',
            ],
            'subject' => [
                'type' => $this->subject_type ? class_basename($this->subject_type) : null,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            // Already scrubbed on the way in — see Redactor. Nothing here is
            // re-filtered on the way out, because a secret must never have been
            // written in the first place.
            'before' => $this->before,
            'after' => $this->after,
            'changed' => $this->changed,
            'ip' => $this->ip,
            /*
             * The raw string, parsed for display in the client.
             *
             * Deliberately not pre-parsed into browser/OS here: user-agent
             * parsing is a heuristic that ages badly, and an audit record must
             * keep what was actually sent. The back-office interprets it; the
             * log stores it.
             */
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'route' => $this->route,
            'method' => $this->method,
            // Present for sensitive reads, where the middleware sees the
            // finished response. Null for mutations — the observer fires while
            // the response is still being built.
            'status_code' => $this->status_code,
            'duration_ms' => $this->duration_ms,
            'context' => $this->context,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
