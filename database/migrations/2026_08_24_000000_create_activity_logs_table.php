<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who did what, when, and from where.
     *
     * There was no audit trail of any kind before this — no package, no
     * observers, no `created_by` on a single table. The only persisted history
     * was the `notifications` table, which records deliveries, not decisions.
     *
     * Three design choices worth defending:
     *
     *  1. **`actor_label` and `subject_label` are denormalised snapshots.**
     *     Joining to `users` to render a log breaks the moment a staff member
     *     is deleted — and the entries you most want to read are the ones
     *     belonging to someone who has since left. The morph columns stay for
     *     linking when the record still exists; the labels are what the log
     *     actually displays.
     *  2. **`before`/`after`/`changed` are separate.** `changed` is the key
     *     list, so filtering "who ever touched a price" is an index scan rather
     *     than a JSON diff across the table.
     *  3. **`request_id` is the join key across four systems** — this table,
     *     Laravel's logs, Sentry, and the HTTP response. Without it they are
     *     four dashboards; with it they are one trail.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Nullable: scheduled jobs and webhooks act with no human behind them.
            $table->nullableMorphs('actor');
            $table->string('actor_label')->nullable();

            // Dotted verb: `order.updated`, `card.blocked`, `invoice.downloaded`.
            $table->string('action');

            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changed')->nullable();

            $table->string('ip', 45)->nullable();          // 45 = IPv6 max
            $table->string('user_agent', 512)->nullable();

            // Correlates this row with the Laravel log lines and the Sentry
            // event produced by the same request.
            $table->uuid('request_id')->nullable();
            $table->string('route')->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('context')->nullable();

            // No `updated_at`. An audit row that can be edited is not an audit
            // row — nothing in the application ever writes to one twice.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index('request_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
