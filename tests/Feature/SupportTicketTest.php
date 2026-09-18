<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketOpened;
use App\Notifications\SupportTicketReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Direct support.
 *
 * The tests that matter most are the refusals. A support thread is the most
 * sensitive free text in the system, and an attachment to one routinely holds a
 * photograph of an identity card or a bank SMS, so who can read one is not a UI
 * detail.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test in here writes attachments. Faking the disk keeps the
        // suite from leaving files in storage/app.
        Storage::fake('local');
    }

    private function client(string $name = 'Passager'): Client
    {
        $this->seq++;

        return Client::create([
            'name' => $name,
            'phone' => '+24206407'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'email' => 'c'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function staff(string $role = 'admin'): User
    {
        $this->seq++;

        return User::create([
            'name' => 'Ops',
            'email' => 'u'.uniqid().'@example.test',
            'phone' => '+2420600'.str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'password' => bcrypt('secret'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function ticketFor(Client $client, string $status = 'open'): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id' => $client->id,
            'subject' => 'Probleme de facturation',
            'category' => 'payment',
            'status' => $status,
            'last_message_at' => now(),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type' => Client::class,
            'sender_id' => $client->id,
            'body' => 'On m\'a facture deux fois.',
        ]);

        return $ticket;
    }

    /* ─────────────────────────── Opening ─────────────────────────── */

    public function test_a_client_can_open_a_ticket(): void
    {
        Notification::fake();
        Sanctum::actingAs($client = $this->client());
        $this->staff();

        $this->postJson('/api/app/v1/support/tickets', [
            'subject' => 'Ma carte ne fonctionne pas',
            'category' => 'pass',
            'body' => 'Le bus refuse ma carte depuis lundi.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.ticket.status', 'open')
            ->assertJsonPath('data.ticket.messages.0.from_client', true);

        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_opening_a_ticket_notifies_active_staff_only(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->client());

        $active = $this->staff();
        $inactive = $this->staff();
        $inactive->update(['status' => 'inactive']);

        $this->postJson('/api/app/v1/support/tickets', [
            'subject' => 'Question',
            'body' => 'Bonjour.',
        ])->assertCreated();

        Notification::assertSentTo($active, SupportTicketOpened::class);
        Notification::assertNotSentTo($inactive, SupportTicketOpened::class);
    }

    public function test_a_subject_and_a_body_are_both_required(): void
    {
        Sanctum::actingAs($this->client());

        $this->postJson('/api/app/v1/support/tickets', ['subject' => 'Coucou'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->postJson('/api/app/v1/support/tickets', ['body' => 'Bonjour.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');
    }

    /** An unknown category is refused rather than silently stored. */
    public function test_the_category_is_restricted_to_the_known_list(): void
    {
        Sanctum::actingAs($this->client());

        $this->postJson('/api/app/v1/support/tickets', [
            'subject' => 'Test',
            'body' => 'Bonjour.',
            'category' => 'refund',
        ])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    /* ─────────────────────────── Scoping ─────────────────────────── */

    public function test_a_client_cannot_read_another_clients_ticket(): void
    {
        $owner = $this->client('Proprietaire');
        $ticket = $this->ticketFor($owner);

        Sanctum::actingAs($this->client('Intrus'));

        $this->getJson("/api/app/v1/support/tickets/{$ticket->id}")->assertNotFound();
    }

    public function test_a_client_cannot_post_into_another_clients_ticket(): void
    {
        $owner = $this->client('Proprietaire');
        $ticket = $this->ticketFor($owner);

        Sanctum::actingAs($this->client('Intrus'));

        $this->postJson("/api/app/v1/support/tickets/{$ticket->id}/messages", [
            'body' => 'Je lis vos messages.',
        ])->assertNotFound();

        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_the_list_shows_only_the_callers_own_tickets(): void
    {
        $this->ticketFor($this->client('Autre'));

        Sanctum::actingAs($mine = $this->client('Moi'));
        $this->ticketFor($mine);

        $this->getJson('/api/app/v1/support/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /* ─────────────────────────── Replying ─────────────────────────── */

    public function test_a_client_reply_reopens_a_resolved_ticket(): void
    {
        Notification::fake();
        Sanctum::actingAs($client = $this->client());

        $ticket = $this->ticketFor($client, 'resolved');
        $ticket->update(['resolved_at' => now()]);
        $this->staff();

        $this->postJson("/api/app/v1/support/tickets/{$ticket->id}/messages", [
            'body' => 'Le probleme est revenu.',
        ])->assertCreated();

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    /**
     * Staff are told about a reopen, and not about every reply.
     *
     * A ticket already in the queue is already visible; mailing every agent each
     * time a customer adds a line is how an alert stops being read.
     */
    public function test_a_reply_on_an_open_ticket_does_not_re_alert_staff(): void
    {
        Notification::fake();
        Sanctum::actingAs($client = $this->client());

        $ticket = $this->ticketFor($client, 'open');
        $this->staff();

        $this->postJson("/api/app/v1/support/tickets/{$ticket->id}/messages", [
            'body' => 'Une precision.',
        ])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_staff_can_reply_and_the_client_is_notified(): void
    {
        Notification::fake();
        $client = $this->client();
        $ticket = $this->ticketFor($client);

        $this->actingAsBackOffice($agent = $this->staff('agent'));

        $this->postJson("/api/admin/support/tickets/{$ticket->id}/reply", [
            'body' => 'Bonjour, nous verifions et revenons vers vous.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.message.from_client', false);

        Notification::assertSentTo($client, SupportTicketReplied::class);

        $ticket->refresh();
        $this->assertSame('answered', $ticket->status);
        // Whoever answers first owns it, so an unassigned queue does not stay
        // unassigned.
        $this->assertSame($agent->id, $ticket->assigned_to);
    }

    public function test_staff_can_reply_and_resolve_in_one_action(): void
    {
        Notification::fake();
        $ticket = $this->ticketFor($this->client());

        $this->actingAsBackOffice($this->staff());

        $this->postJson("/api/admin/support/tickets/{$ticket->id}/reply", [
            'body' => 'Rembourse ce matin.',
            'resolve' => true,
        ])->assertCreated();

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
    }

    /* ─────────────────────────── The staff gate ─────────────────────────── */

    /**
     * `auth:sanctum` alone is not staff.
     *
     * `Client` owns tokens too. This is the single most important refusal in the
     * file: without the `staff` middleware these routes hand every customer the
     * entire support history of every other customer.
     */
    public function test_a_client_token_cannot_reach_the_back_office_routes(): void
    {
        $ticket = $this->ticketFor($this->client());

        Sanctum::actingAs($this->client('Intrus'));

        $this->getJson('/api/admin/support/tickets')->assertForbidden();
        $this->getJson("/api/admin/support/tickets/{$ticket->id}")->assertForbidden();
        $this->getJson('/api/admin/support/tickets/pending-count')->assertForbidden();
        $this->postJson("/api/admin/support/tickets/{$ticket->id}/reply", ['body' => 'Coucou'])
            ->assertForbidden();
        $this->postJson("/api/admin/support/tickets/{$ticket->id}/resolve")->assertForbidden();
    }

    /** `pending-count` is declared before `{id}`, so it is not read as an id. */
    public function test_the_pending_count_route_is_not_swallowed_by_the_id_wildcard(): void
    {
        $this->ticketFor($this->client(), 'open');
        $this->ticketFor($this->client(), 'answered');
        $this->ticketFor($this->client(), 'resolved');

        $this->actingAsBackOffice($this->staff());

        $this->getJson('/api/admin/support/tickets/pending-count')
            ->assertOk()
            // Only `open`. A ticket we have answered is not waiting on us.
            ->assertJsonPath('data.count', 1);
    }

    /* ─────────────────────────── Attachments ─────────────────────────── */

    public function test_an_attachment_lands_on_the_private_disk(): void
    {
        Notification::fake();
        Sanctum::actingAs($client = $this->client());
        $this->staff();

        $this->post('/api/app/v1/support/tickets', [
            'subject' => 'Facture incorrecte',
            'body' => 'Voici la capture.',
            'attachments' => [UploadedFile::fake()->image('recu.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = SupportAttachment::firstOrFail();

        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);

        /*
         * The stored name is a uuid this server generated, never the client's.
         * A filename is caller-supplied text, and concatenating one into a path
         * is how `../` gets somewhere it should not.
         */
        $this->assertStringNotContainsString('recu', $attachment->path);
        $this->assertSame('recu.jpg', $attachment->original_name);
        $this->assertGreaterThan(0, $attachment->size_bytes);
    }

    public function test_a_non_image_upload_is_refused(): void
    {
        Sanctum::actingAs($this->client());

        $this->post('/api/app/v1/support/tickets', [
            'subject' => 'Document',
            'body' => 'Ci-joint.',
            'attachments' => [UploadedFile::fake()->create('contrat.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0');
    }

    public function test_more_than_four_attachments_are_refused(): void
    {
        Sanctum::actingAs($this->client());

        $this->post('/api/app/v1/support/tickets', [
            'subject' => 'Beaucoup de captures',
            'body' => 'Voici.',
            'attachments' => array_map(
                fn (int $i) => UploadedFile::fake()->image("c{$i}.jpg"),
                range(1, 5),
            ),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');
    }

    /**
     * The whole point of putting these on `local`.
     *
     * Without a valid signature the route refuses, so an attachment id is not a
     * way to read somebody else's screenshot by counting upwards.
     */
    public function test_an_attachment_is_unreachable_without_a_signature(): void
    {
        $attachment = $this->attachmentFor($this->client());

        $this->get("/api/app/v1/support/attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_a_signed_link_serves_the_file(): void
    {
        $client = $this->client();
        $attachment = $this->attachmentFor($client);

        Sanctum::actingAs($client);

        $url = $this->getJson("/api/app/v1/support/attachments/{$attachment->id}/link")
            ->assertOk()
            ->json('data.url');

        $this->get($url)->assertOk();
    }

    public function test_a_client_cannot_mint_a_link_for_another_clients_attachment(): void
    {
        $attachment = $this->attachmentFor($this->client('Proprietaire'));

        Sanctum::actingAs($this->client('Intrus'));

        $this->getJson("/api/app/v1/support/attachments/{$attachment->id}/link")
            ->assertNotFound();
    }

    /** An expired signature is refused, which is what makes the TTL mean anything. */
    public function test_an_expired_link_is_refused(): void
    {
        $attachment = $this->attachmentFor($this->client());

        $url = URL::temporarySignedRoute(
            'support.attachment',
            now()->subMinute(),
            ['attachment' => $attachment->id],
        );

        $this->get($url)->assertForbidden();
    }

    /**
     * A ticket carrying an attachment, with a real file on the fake disk.
     *
     * Goes through the endpoint rather than building rows by hand, so the test
     * exercises the same storage path production uses.
     */
    private function attachmentFor(Client $client): SupportAttachment
    {
        Notification::fake();
        Sanctum::actingAs($client);

        $this->post('/api/app/v1/support/tickets', [
            'subject' => 'Avec piece jointe',
            'body' => 'Voir la capture.',
            'attachments' => [UploadedFile::fake()->image('capture.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        return SupportAttachment::latest('id')->firstOrFail();
    }
}
