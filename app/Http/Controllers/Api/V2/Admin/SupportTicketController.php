<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Support\AttachmentStore;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReplied;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Support, from the back office.
 *
 * Behind `staff`, which is the gate that matters: `auth:sanctum` alone would not
 * do, because `Client` owns tokens too and this controller reads every
 * customer's support history.
 *
 * **Assignment and resolution are audited explicitly, the messages are not.**
 * The tickets themselves are deliberately NOT registered with
 * `ActivityObserver`: the audit table is append-only and retained for months,
 * and duplicating customer free text into it would make it the largest store of
 * exactly the content the Redactor exists to keep out. Who took a ticket and who
 * closed it is the question an audit answers; what was said is in the ticket,
 * which nothing here can edit.
 */
class SupportTicketController extends Controller
{
    public function __construct(
        private AttachmentStore $attachments,
        private ActivityLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:open,answered,resolved'],
            'category' => ['nullable', 'string', 'in:' . implode(',', SupportTicket::CATEGORIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $tickets = SupportTicket::query()
            ->with(['client:id,name,phone', 'assignee:id,name'])
            ->withCount([
                // What the queue is actually sorted by attention: how many of
                // the client's messages nobody has opened.
                'messages as unread_count' => fn ($q) => $q
                    ->where('sender_type', Client::class)
                    ->whereNull('read_at'),
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('assigned_to'),
                fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search') . '%';

                $q->where(fn ($inner) => $inner
                    ->where('subject', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25));

        /*
         * `->items()`, not the paginator itself.
         *
         * Handing `response()->json()` a paginator serialises the whole
         * LengthAwarePaginator, so rows land at `data.data` and the caller reads
         * `data.0` as null. The pagination facts are in `meta`.
         */
        return response()->json([
            'status' => true,
            'data' => $tickets->through(fn (SupportTicket $t) => $this->row($t))->items(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * How many tickets are waiting on a person.
     *
     * A count rather than a list: it is polled on every back-office screen and
     * the sidebar badge only needs a number. Declared BEFORE `/{id}` in the
     * routes file, or `pending-count` is read as a ticket id.
     */
    public function pendingCount()
    {
        return response()->json([
            'status' => true,
            'data' => ['count' => SupportTicket::where('status', 'open')->count()],
        ]);
    }

    /**
     * One thread, with the client's messages marked as read.
     *
     * Opening a ticket IS reading it, the same rule the customer's side uses, so
     * there is no separate endpoint the back office could forget to call.
     */
    public function show(Request $request, string $id)
    {
        $ticket = SupportTicket::with([
            'client:id,name,phone,email',
            'assignee:id,name',
            'messages.sender',
            'messages.attachments',
        ])->findOrFail($id);

        SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('sender_type', Client::class)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => true,
            'data' => [
                'ticket' => $this->row($ticket) + [
                    'messages' => $ticket->messages->map->toWire()->values(),
                ],
            ],
        ]);
    }

    /** Replies, as the signed-in staff member. */
    public function reply(Request $request, string $id)
    {
        /** @var User $staff */
        $staff = $request->user();
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:' . AttachmentStore::MAX_FILES],
            'attachments.*' => AttachmentStore::rules(),
            // Closes it in the same action. Replying and then resolving is two
            // requests and, in practice, one of them gets forgotten.
            'resolve' => ['nullable', 'boolean'],
        ]);

        $message = DB::transaction(function () use ($ticket, $staff, $data, $request) {
            $message = SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => User::class,
                'sender_id' => $staff->id,
                'body' => trim($data['body']),
            ]);

            $this->attachments->attach($message, $request->file('attachments', []));

            // Sets the status to `answered` and stamps the activity time.
            $ticket->recordMessage($message);

            /*
             * Whoever answers first owns it, unless somebody already does.
             *
             * Assignment is otherwise a step nobody performs, and an unassigned
             * queue is one where two agents write the same reply.
             */
            if ($ticket->assigned_to === null) {
                $ticket->forceFill(['assigned_to' => $staff->id])->save();
            }

            if ($request->boolean('resolve')) {
                $ticket->forceFill([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ])->save();
            }

            return $message;
        });

        $this->notifyClient($ticket->fresh(), $message);

        $message->load(['sender', 'attachments']);

        return response()->json([
            'status' => true,
            'data' => ['message' => $message->toWire()],
        ], 201);
    }

    /** Hands a ticket to a colleague, or takes it. */
    public function assign(Request $request, string $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            // Nullable so a ticket can be put back in the unassigned queue.
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $before = $ticket->assigned_to;
        $ticket->forceFill(['assigned_to' => $data['assigned_to'] ?? null])->save();

        $this->audit->log(
            'support_ticket.assigned',
            $ticket,
            ['assigned_to' => $before],
            ['assigned_to' => $ticket->assigned_to],
        );

        return response()->json([
            'status' => true,
            'data' => $this->row($ticket->fresh(['client', 'assignee'])),
        ]);
    }

    /**
     * Marks a ticket done.
     *
     * Not a deletion, and there is no delete route here at all. A support thread
     * is the record of what a customer was told, which is the last thing an
     * operator should be able to remove. A client message reopens it.
     */
    public function resolve(Request $request, string $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $before = $ticket->status;

        $ticket->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
        ])->save();

        $this->audit->log(
            'support_ticket.resolved',
            $ticket,
            ['status' => $before],
            ['status' => 'resolved'],
        );

        return response()->json([
            'status' => true,
            'data' => $this->row($ticket->fresh(['client', 'assignee'])),
        ]);
    }

    /**
     * One row of the back-office list.
     *
     * Carries the client's name and phone, which the customer-facing `toWire()`
     * deliberately does not: an agent picking up a ticket needs to be able to
     * ring the person back.
     *
     * @return array<string, mixed>
     */
    private function row(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'client_id' => $ticket->client_id,
            'client' => $ticket->client?->name,
            'client_phone' => $ticket->client?->phone,
            'assigned_to' => $ticket->assigned_to,
            'assignee' => $ticket->assignee?->name,
            'unread_count' => (int) ($ticket->unread_count ?? 0),
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    /**
     * Tells the client somebody answered.
     *
     * Wrapped, because a push service being unreachable must never turn a reply
     * an agent successfully sent into an error that makes them send it twice.
     */
    private function notifyClient(?SupportTicket $ticket, SupportMessage $message): void
    {
        if ($ticket?->client === null) {
            return;
        }

        try {
            Notification::send($ticket->client, new SupportTicketReplied($ticket, $message));
        } catch (Throwable $e) {
            Log::error('Support reply saved but the client could not be notified', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
