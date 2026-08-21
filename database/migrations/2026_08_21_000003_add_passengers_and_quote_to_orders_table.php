<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two facts the orders table could not previously hold.
     *
     * `passengers` — the head count was only ever implied by the vehicle mix,
     * so ops could not tell "3 Hiaces for 40 people" from "3 Hiaces for 12" and
     * had to phone to find out.
     *
     * `quoted_total` — the app now shows a price before the order is sent. That
     * number has to be stored, or the client and the team are looking at
     * different figures when the invoice is raised. It is RECOMPUTED server-side
     * at creation (see OrderRequestController), never taken from the request,
     * so it is a record of what Mova quoted rather than what the phone claimed.
     *
     * Both nullable: every existing row predates them, and an order whose route
     * cannot be geocoded still has no price until a human sets one.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('passengers')->nullable()->after('fleet_requirements');
            $table->decimal('quoted_total', 12, 2)->nullable()->after('passengers');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['passengers', 'quoted_total']);
        });
    }
};
