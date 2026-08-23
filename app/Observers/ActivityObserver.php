<?php

namespace App\Observers;

use App\Domain\Audit\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records create / update / delete for every audited model.
 *
 * Registered per-model in AppServiceProvider rather than globally, so adding a
 * model to the audit is a deliberate line of code and a new table does not
 * start logging itself by accident.
 *
 * **Know what this does NOT catch.** Eloquent events fire on model saves only.
 * Every `Builder::update()` and `Builder::delete()` bypasses them completely —
 * which in this codebase means all five bulk endpoints
 * (`Reservation@bulkStatus`, `Staff@bulkStatus`, `Person@bulkStatus`,
 * `Bus@bulkStatus`, `Bus@bulkDestroy`) plus `SubscriptionService::expireLapsed()`.
 * Those call `ActivityLogger` explicitly. An observer-only design would have
 * silently missed exactly the mass actions most worth auditing.
 */
class ActivityObserver
{
    public function __construct(private ActivityLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->log(
            $this->action($model, 'created'),
            $model,
            null,
            // On a create the "dirty" set is everything, so the whole row IS
            // the change — but it goes through the redactor like anything else.
            $model->getAttributes(),
        );
    }

    public function updated(Model $model): void
    {
        $this->logger->logModelChange($this->action($model, 'updated'), $model);
    }

    public function deleted(Model $model): void
    {
        // Soft deletes arrive here too. `deleted_at` in the diff is what tells
        // them apart from a hard delete when reading the log back.
        $this->logger->log(
            $this->action($model, 'deleted'),
            $model,
            $model->getOriginal(),
            null,
        );
    }

    public function restored(Model $model): void
    {
        $this->logger->log($this->action($model, 'restored'), $model);
    }

    /**
     * `App\Models\PassCard` + `blocked` → `pass_card.blocked`.
     *
     * Derived rather than declared so a new audited model needs no mapping
     * table, and snake_case so the verbs sort and filter predictably.
     */
    private function action(Model $model, string $verb): string
    {
        return Str::snake(class_basename($model)) . '.' . $verb;
    }
}
