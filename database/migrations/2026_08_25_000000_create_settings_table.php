<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runtime configuration, editable without a deploy.
     *
     * Key/value rather than the wide-column shape `company_settings` uses:
     * that table needs an ALTER for every new setting, which puts a migration
     * between ops and a fee change. Grouped so the Settings page can fetch one
     * tab at a time.
     *
     * **`value` is always JSON**, even for a scalar. A `string` column would
     * silently turn `false` into `"false"` and `0` into `"0"`, and a settings
     * store that cannot represent a boolean is a settings store that will one
     * day enable something it was told to disable.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            /** payment | wallet | rules | notifications | pricing | billing | general */
            $table->string('group')->index();
            $table->string('key');

            $table->json('value')->nullable();

            /*
             * Marks a value that must never leave the server in a read
             * response, and must be redacted in the activity log. The
             * Redactor's key list catches the obvious names; this catches the
             * ones nobody thought of.
             */
            $table->boolean('is_secret')->default(false);

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
