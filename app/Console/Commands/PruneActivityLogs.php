<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * Ages the audit trail out.
 *
 * Two retentions, because the two halves have different value and different
 * risk. Mutations are the accountability record and are kept long. Sensitive
 * READS are far higher volume and far lower value after the fact — nobody asks
 * who viewed an invoice nine months ago, and keeping those rows means keeping
 * a much larger map of who looked at whose data.
 *
 * Deletes in chunks. A single `DELETE ... WHERE created_at < ?` over a table
 * this size locks it for the duration, on a table every write path touches.
 */
class PruneActivityLogs extends Command
{
    protected $signature = 'activity:prune
        {--days=400 : Retention for mutations}
        {--access-days=90 : Retention for sensitive-read entries}
        {--chunk=1000 : Rows per delete}';

    protected $description = 'Delete audit entries past their retention window';

    public function handle(): int
    {
        $chunk = max(100, (int) $this->option('chunk'));

        $mutations = $this->prune(
            ActivityLog::mutationsOnly()->where('created_at', '<', now()->subDays((int) $this->option('days'))),
            $chunk,
        );

        $access = $this->prune(
            ActivityLog::accessOnly()->where('created_at', '<', now()->subDays((int) $this->option('access-days'))),
            $chunk,
        );

        $this->info("Pruned {$mutations} mutation entries and {$access} access entries.");

        return self::SUCCESS;
    }

    private function prune($query, int $chunk): int
    {
        $total = 0;

        do {
            // Re-running the same limited delete rather than paginating: the
            // window is a moving target and offset-based paging over rows you
            // are deleting skips records.
            $deleted = (clone $query)->limit($chunk)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
