<?php

namespace App\Domain\Audit\Support;

use App\Domain\Audit\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bulk mutations that Eloquent — and therefore the audit trail — can see.
 *
 * `Builder::update()` and `Builder::delete()` issue one SQL statement and never
 * hydrate a model, so no observer fires. Every bulk endpoint in this codebase
 * was written that way, which meant the five actions with the widest blast
 * radius were the only ones producing no audit record at all, and (for
 * reservations) no customer notification either.
 *
 * The loop is slower. That is the right trade here: these operate on rows an
 * agent hand-picked in a table view, so the counts are tens. The endpoints cap
 * their input to keep it that way — if a genuine mass migration is ever needed,
 * it should be a command with its own single summary entry, not this.
 */
trait PerformsAuditedBulkUpdates
{
    /**
     * Applies `$changes` to every matching row, one save at a time.
     *
     * Rows already holding the target values are skipped rather than re-saved:
     * an audit entry recording a change from `active` to `active` is noise, and
     * enough of it makes the log unreadable.
     *
     * @param  array<string, mixed>  $changes
     * @return int rows actually changed
     */
    protected function auditedBulkUpdate(Builder $query, array $changes, string $action, array $context = []): int
    {
        $changedCount = 0;

        $query->chunkById(100, function ($models) use ($changes, &$changedCount) {
            foreach ($models as $model) {
                $model->fill($changes);

                if (! $model->isDirty()) {
                    continue;
                }

                $model->save(); // Observer fires → one audit row per record.
                $changedCount++;
            }
        });

        // Plus one summary entry, so the log shows the single operator action
        // alongside the per-record rows it produced. Reading a log of forty
        // individual updates without it, you cannot tell one bulk click from
        // forty deliberate edits.
        app(ActivityLogger::class)->log(
            $action,
            context: $context + ['changes' => $changes, 'affected' => $changedCount],
        );

        return $changedCount;
    }

    /**
     * Deletes every matching row, one at a time.
     *
     * The `before` state is captured by the observer's `deleted` hook, which is
     * the entire reason this cannot stay a query-builder delete: a mass
     * deletion with no record of what was in the rows is unrecoverable and
     * unexplainable.
     */
    protected function auditedBulkDelete(Builder $query, string $action, array $context = []): int
    {
        $deleted = 0;

        // NOT chunkById: deleting while paginating by id skips rows, because
        // the cursor advances past records the previous chunk removed.
        foreach ($query->get() as $model) {
            $model->delete();
            $deleted++;
        }

        app(ActivityLogger::class)->log($action, context: $context + ['deleted' => $deleted]);

        return $deleted;
    }
}
