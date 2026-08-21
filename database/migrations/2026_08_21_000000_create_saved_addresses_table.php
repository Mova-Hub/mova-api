<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            /**
             * `home`, `work`, `school` are the fixed shortcuts the app surfaces;
             * `custom` covers anything else the user names themselves.
             */
            $table->enum('kind', ['home', 'work', 'school', 'custom'])->default('custom');

            /** Only used for `custom` — the fixed kinds get their label from the app. */
            $table->string('label')->nullable();

            /** Human-readable address, as chosen from the Places result. */
            $table->string('address');

            /**
             * Coordinates are stored so a booking can compute distance without
             * re-geocoding. Nullable because an address typed by hand (no Places
             * match) is still worth saving.
             */
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            /** Lets us refresh a stale address later without re-searching. */
            $table->string('place_id')->nullable();

            $table->timestamps();

            /**
             * One home, one work, one school per client — but any number of
             * custom entries, so `custom` rows are excluded from the constraint
             * by using a partial-style guard in the controller instead of a
             * DB-level unique index (MySQL has no partial indexes).
             */
            $table->index(['client_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_addresses');
    }
};
