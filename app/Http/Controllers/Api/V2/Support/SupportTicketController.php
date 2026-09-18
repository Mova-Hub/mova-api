<?php

namespace App\Http\Controllers\Api\V2\Support;

use App\Domain\Support\AttachmentStore;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketOpened;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The client's half of support.
 *
 * The staff half is `Admin\SupportTicketController`, behind `staff`. Two
 * controllers rather than one branching on the caller's model, for the reason
 * `TripMessageController` gives: the two callers authenticate as different
 * models and are authorised by completely different rules, and a single
 * controller branching on `instanceof` is one missing branch away from letting a
 * customer reply as an agent.
 *
 * **Every handler here resolves through `owned()`.** An id in a URL is a claim,
 * not an authorisation, and `findOrFail($id)` on a ticket id would let any
 * signed-in client read any other client's support history, which is by
 * construction the most sensitive free text in the system.
 */
class SupportTicketController extends Controller
{
    public function __construct(private AttachmentStore $attachments) {}

    /** The client's own tickets, most recently active first. */
    public function index(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        $tickets = SupportTicket::where('client_id', $client->id)
            ->with('assignee:id,name')
            // `last_message_at` and not `created_at`: a three-week-old ticket
            // that was answered this morning belongs at the top.
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'status' => true,
            'data' => $tickets->map->toWire()->values(),
        ]);
    }

    /**
     * Opens a ticket.
     *
     * Multipart, because the first message can carry screenshots and asking
     * somebody to open a ticket and then attach the evidence separately is two
     * chances to give up.
     */
    public function store(Request $request)
    {
        /** @var Client $client */
        $client = $request->user();

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'category' => ['nullable', 'string', 'in:' . implode(',', SupportTicket::CATEGORIES)],
            // Same ceiling as a trip message, and for the same reasons: generous
            // for a person typing, small enough that the column and any push
            // payload built from it stay sane.
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:' . AttachmentStore::MAX_FILES],
            'attachments.*' => AttachmentStore::rules(),
        ]);

        /*
         * The ticket, its first message and its attachments are one write.
         *
         * A ticket with no message is a row nobody can read and nobody can
         * answer, so a failure part-way through must leave nothing rather than
         * leave that.
         */
        $ticket = DB::transaction(function () use ($client, $data, $request) {
            $ticket = SupportTicket::create([
                'client_id' => $client->id,
                'subject' => trim($data['subject']),
                // `?? null` because `validate()` returns only the keys that were
                // both ruled and sent, so an absent optional field is simply not
                // in the array. This has bitten this codebase before.
                'category' => $data['category'] ?? 'other',
                'status' => 'open',
                'last_message_at' => now(),
            ]);

            $message = SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => Client::class,
                'sender_id' => $client->id,
                'body' => trim($data['body']),
            ]);

            $this->attachments->attach($message, $request->file('attachments', []));

            return $ticket;
        });

        // After the commit, never inside it. A rollback would otherwise tell
        // every agent to go and read a ticket that does not exist.
        $this->alertStaff($ticket);

        $ticket->load(['assignee:id,name', 'messages.sender', 'messages.attachments']);

        return response()->json([
            'status' => true,
            'data' => ['ticket' => $ticket->toWire(withMessages: true)],
        ], 201);
    }

    /**
     * One thread.
     *
     * Reading marks the STAFF messages as read, matching the trip conversation:
     * there is no separate "seen" call the app could forget to make, and a
     * thread you have open is a thread you have read.
     */
    public function show(Request $request, string $id)
    {
        $ticket = $this->owned($request->user(), $id);

        SupportMessage::where('support_ticket_id', $ticket->id)
            // Only the other side's. Marking your own as read is meaningless.
            ->where('sender_type', '!=', Client::class)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $ticket->load(['assignee:id,name', 'messages.sender', 'messages.attachments']);

        return response()->json([
            'status' => true,
            'data' => ['ticket' => $ticket->toWire(withMessages: true)],
        ]);
    }

    /**
     * Replies on an existing ticket.
     *
     * A resolved ticket accepts this and reopens, see `SupportTicket::
     * recordMessage()`. Somebody who writes again has not been helped, whatever
     * the last agent concluded.
     */
    public function storeMessage(Request $request, string $id)
    {
        /** @var Client $client */
        $client = $request->user();
        $ticket = $this->owned($client, $id);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:' . AttachmentStore::MAX_FILES],
            'attachments.*' => AttachmentStore::rules(),
        ]);

        $wasResolved = $ticket->status === 'resolved';

        $message = DB::transaction(function () use ($ticket, $client, $data, $request) {
            $message = SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => Client::class,
                'sender_id' => $client->id,
                'body' => trim($data['body']),
            ]);

            $this->attachments->attach($message, $request->file('attachments', []));
            $ticket->recordMessage($message);

            return $message;
        });

        /*
         * Staff are told about a reopen, not about every reply.
         *
         * A ticket already sitting in the queue is already visible; mailing
         * every agent each time a customer adds a line is how an alert stops
         * being read. A ticket that had been resolved is different: nobody is
         * watching it any more.
         */
        if ($wasResolved) {
            $this->alertStaff($ticket->fresh());
        }

        $message->load(['sender', 'attachments']);

        return response()->json([
            'status' => true,
            'data' => ['message' => $message->toWire()],
        ], 201);
    }

    /**
     * Resolves a ticket id to one this client actually owns.
     *
     * The `client_id` scope IS the authorisation.
     */
    private function owned(Client $client, string $id): SupportTicket
    {
        return SupportTicket::where('client_id', $client->id)->findOrFail($id);
    }

    /**
     * Tells staff a ticket is waiting.
     *
     * Active admins and agents, matching how a manual payment request is
     * announced. Wrapped, because a mail server being down must never turn a
     * ticket the customer successfully filed into a 500 that tells them it
     * failed.
     */
    private function alertStaff(?SupportTicket $ticket): void
    {
        if ($ticket === null) {
            return;
        }

        try {
            $staff = User::whereIn('role', User::STAFF_ROLES)
                ->where('status', 'active')
                ->get();

            if ($staff->isEmpty()) {
                return;
            }

            Notification::send($staff, new SupportTicketOpened($ticket));
        } catch (Throwable $e) {
            Log::error('Support ticket filed but staff could not be alerted', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
