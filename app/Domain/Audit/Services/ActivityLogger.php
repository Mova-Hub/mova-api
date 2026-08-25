<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Support\Redactor;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one writer for the audit trail.
 *
 * Everything that records an action goes through here — the model observer, the
 * bulk-operation call sites, and the sensitive-read middleware. One writer means
 * one place where redaction happens and one place where the actor is resolved,
 * rather than each caller getting it subtly differently.
 *
 * **Logging must never break the thing it is logging.** Every write is wrapped:
 * if the audit table is missing, full, or locked, the request it is describing
 * still succeeds and the failure goes to the application log. An audit system
 * that can take down an order submission is worse than no audit system.
 */
class ActivityLogger
{
    /** Correlates every row, log line and Sentry event from one request. */
    private static ?string $requestId = null;

    public function __construct(private Redactor $redactor) {}

    public static function requestId(): string
    {
        return self::$requestId ??= (string) Str::uuid();
    }

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    /**
     * Milliseconds from the start of the request to this moment.
     *
     * For a mutation this is time-to-the-change, not total request time — the
     * observer fires while the response is still being built, so the request
     * has not finished and its real duration is unknowable here. The
     * sensitive-read middleware passes the true figure explicitly because it
     * runs after the response exists.
     *
     * `LARAVEL_START` is defined in public/index.php. Absent under artisan and
     * in tests, in which case there is no meaningful duration to report.
     */
    private static function elapsedMs(): ?int
    {
        if (! defined('LARAVEL_START')) {
            return null;
        }

        return (int) round((microtime(true) - LARAVEL_START) * 1000);
    }

    /**
     * Records an action.
     *
     * @param  string      $action      dotted verb, e.g. `order.updated`
     * @param  Model|null  $subject     what it happened to
     * @param  array|null  $before      attributes before the change
     * @param  array|null  $after       attributes after
     * @param  int|null    $statusCode  HTTP status, when the caller knows it
     * @param  int|null    $durationMs  overrides the elapsed-since-boot default
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        array $context = [],
        ?int $statusCode = null,
        ?int $durationMs = null,
    ): ?ActivityLog {
        try {
            $actor = $this->resolveActor();
            $request = request();

            $before = $this->redactor->scrub($before);
            $after = $this->redactor->scrub($after);

            return ActivityLog::create([
                'uuid' => (string) Str::uuid(),
                'actor_type' => $actor ? $actor::class : null,
                'actor_id' => $actor?->getKey(),
                // Snapshot, not a join. The entries most worth reading belong
                // to people who have since left.
                'actor_label' => $this->labelFor($actor),
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $this->labelFor($subject),
                'before' => $before,
                'after' => $after,
                // The key list, stored separately so "who ever touched a price"
                // is a cheap query instead of a JSON diff across the table.
                'changed' => $after !== null ? array_keys($after) : null,
                'ip' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 500, ''),
                'request_id' => self::requestId(),
                'route' => $request?->path(),
                'method' => $request?->method(),
                /*
                 * First-class columns, not buried in `context`.
                 *
                 * They were created, made fillable and cast, and then nothing
                 * ever wrote to them — the sensitive-read middleware put both
                 * inside the `context` JSON instead, so "show me every action
                 * that took over two seconds" was not a query anyone could
                 * write. That is the entire reason a column was chosen over a
                 * JSON key in the first place.
                 */
                'status_code' => $statusCode,
                'duration_ms' => $durationMs ?? self::elapsedMs(),
                'context' => $context ?: null,
            ]);
        } catch (Throwable $e) {
            // Deliberately swallowed — see the class docblock.
            Log::warning('Activity log write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Records a mutation from a model's dirty state.
     *
     * Only the attributes that actually changed, with their previous values
     * beside them. Storing whole rows would make every entry mostly noise and
     * would drag unchanged secrets into the table for no reason.
     */
    public function logModelChange(string $action, Model $model): ?ActivityLog
    {
        $after = $model->getDirty();

        if ($after === []) {
            return null;
        }

        $before = array_intersect_key($model->getOriginal(), $after);

        return $this->log($action, $model, $before, $after);
    }

    /**
     * Who is acting.
     *
     * `Auth::user()` would resolve only the default guard. Both `User` (staff)
     * and `Client` (customers) own Sanctum tokens, and both mutate data worth
     * auditing — a customer editing a saved address is as much an actor as an
     * agent editing a reservation.
     */
    private function resolveActor(): ?Model
    {
        $user = request()?->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * A human label, resolved once at write time.
     *
     * Falls back through the fields a Mova model is likely to have, then to the
     * class and key — never to nothing, because a log entry that cannot say
     * what it refers to is not worth having written.
     */
    private function labelFor(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach (['name', 'code', 'plate', 'title', 'label', 'chip_uid', 'reference'] as $field) {
            $value = $model->getAttribute($field);
            if (is_string($value) && $value !== '') {
                return Str::limit($value, 120, '');
            }
        }

        return class_basename($model) . ' #' . $model->getKey();
    }
}
