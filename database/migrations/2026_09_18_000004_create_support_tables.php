<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct support: a client writes to Mova and a human writes back.
 *
 * Three tables in one migration because they are one thing. A ticket with no
 * messages is not a half-built feature, it is a broken row, and splitting the
 * create across three files only means three ways for a rollback to leave the
 * schema half standing.
 *
 * Modelled on the trip conversation (`trip_messages`), with one deliberate
 * difference: a trip thread hangs off a reservation and dies with it, whereas a
 * support ticket belongs to the CLIENT and outlives any single booking. Somebody
 * writing in about a refund three weeks after the trip still has a thread.
 *
 * **Attachments record a disk, not a URL.** They live on `local`, which is not
 * web reachable, and they are served through a signed route. A support
 * screenshot routinely holds an identity card or a bank SMS, which is precisely
 * what the four existing uploads in this codebase put on the `public` disk
 * behind a guessable unauthenticated path. That was acceptable for a bus
 * insurance certificate and a provider logo. It is not acceptable here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('subject');

            /*
             * What the client says it is about, not what ops decides it is.
             *
             * Used to route and to filter, never to gate: a ticket filed under
             * the wrong category is still a ticket, so nothing in the code
             * branches on this value.
             */
            $table->enum('category', [
                'booking',
                'payment',
                'pass',
                'account',
                'other',
            ])->default('other');

            /*
             * Three states, and they are derived from who spoke last rather than
             * set by hand:
             *
             *  - `open`     the client is waiting on us. This is the back-office
             *               queue and the sidebar badge.
             *  - `answered` we replied, the client has not come back.
             *  - `resolved` staff closed it. A client message reopens it, so a
             *               premature resolve costs nothing.
             *
             * A fourth state for "closed forever" was considered and dropped: it
             * would exist only to stop somebody replying, and refusing a reply
             * to a customer who has more to say is not a feature.
             */
            $table->enum('status', ['open', 'answered', 'resolved'])->default('open');

            /*
             * Who picked it up. Nullable because unassigned is the normal state
             * for a new ticket, and `nullOnDelete` because an agent leaving must
             * not delete the customer's complaint along with their account.
             */
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Denormalised so the list can sort by activity without a join onto
             * the messages table and a per-row aggregate. Written on every
             * message; it is the only column in here that is hot.
             */
            $table->timestamp('last_message_at')->nullable();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The client's own list, newest first.
            $table->index(['client_id', 'last_message_at']);
            // The back-office queue, and the count behind the sidebar badge.
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

            /*
             * Polymorphic, exactly as `trip_messages` is, because the two ends
             * of this conversation are different models: a `Client` writes in
             * and a `User` writes back. A `sender_id` alone could not tell a
             * client id from a staff id, and the day those two sequences
             * collide, a customer's message is attributed to an employee.
             */
            $table->string('sender_type');
            $table->unsignedBigInteger('sender_id');

            $table->text('body');

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
            $table->index(['sender_type', 'sender_id']);
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_message_id')->constrained()->cascadeOnDelete();

            /*
             * The disk is stored, not assumed.
             *
             * Today every row says `local`. Storing it means a future move to
             * object storage is a per-row fact rather than a migration that has
             * to guess where files written last year actually ended up.
             */
            $table->string('disk')->default('local');
            $table->string('path');

            /*
             * What the client's phone called it, kept only to name the download.
             *
             * Never used to build a path: a filename is caller-supplied text and
             * concatenating it into a path is how `../` gets somewhere it should
             * not. The stored path is a uuid this server generated.
             */
            $table->string('original_name');

            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');

            $table->timestamps();

            $table->index('support_message_id');
        });
    }

    public function down(): void
    {
        /*
         * Children first, or the foreign keys refuse the drop on MySQL.
         *
         * Note what this does NOT do: it does not delete the files those rows
         * point at. A rollback is a schema operation, and a migration that
         * silently erases a customer's uploaded evidence because somebody
         * stepped a deploy back is not a trade worth making. Orphaned files on
         * `local` cost disk; deleted ones cost a dispute.
         */
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
