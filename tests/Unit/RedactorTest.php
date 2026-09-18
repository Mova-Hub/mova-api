<?php

namespace Tests\Unit;

use App\Domain\Audit\Support\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * What reaches the audit log.
 *
 * The audit table is append-only and retained for months, which makes it the
 * single largest concentration of personal data in the system and the one place
 * a mistake cannot be corrected by deleting a row. These tests are the contract.
 */
class RedactorTest extends TestCase
{
    private Redactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new Redactor;
    }

    /* ─────────────────────────── Secrets ─────────────────────────── */

    public function test_secrets_are_replaced_entirely(): void
    {
        $out = $this->redactor->scrub([
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'remember_token' => 'abc',
            'otp' => '123456',
            'api_key' => 'sk_live_x',
        ]);

        foreach ($out as $key => $value) {
            $this->assertSame(Redactor::REDACTED, $value, "$key was not redacted");
        }
    }

    public function test_identifiers_keep_only_their_tail(): void
    {
        $out = $this->redactor->scrub(['phone' => '+242064074926']);

        $this->assertStringEndsWith('4926', $out['phone']);
        $this->assertStringNotContainsString('064074', $out['phone']);
    }

    /* ─────────────────────────── Free text ─────────────────────────── */

    /**
     * Support tickets and trip messages.
     *
     * The content is a customer's own words, it lives in a table nothing can
     * edit, and duplicating it here protects nobody and exposes everybody.
     */
    public function test_free_text_is_replaced_with_its_length(): void
    {
        $out = $this->redactor->scrub([
            'body' => 'On m\'a facture deux fois le 12 janvier.',
            'subject' => 'Probleme de facturation',
            'message' => 'Bonjour',
            'comment' => 'Chauffeur ponctuel.',
        ]);

        foreach (['body', 'subject', 'message', 'comment'] as $key) {
            $this->assertStringStartsWith(Redactor::REDACTED, $out[$key], "$key leaked");
            $this->assertStringContainsString('caractères', $out[$key]);
        }

        // The LENGTH survives, because "the reply grew from 40 to 900
        // characters" is a real signal with no privacy cost.
        $this->assertStringContainsString('7 caractères', $out['message']);
    }

    /**
     * The text list is matched exactly, not as a substring.
     *
     * `message` as a substring would also catch these, and redacting them costs
     * the log its debugging value while protecting nothing.
     */
    public function test_keys_merely_containing_a_text_word_are_left_alone(): void
    {
        $out = $this->redactor->scrub([
            'error_message' => 'SQLSTATE[23000]: duplicate key',
            'message_id' => 'msg_8812',
            'body_style' => 'coaster',
        ]);

        $this->assertSame('SQLSTATE[23000]: duplicate key', $out['error_message']);
        $this->assertSame('msg_8812', $out['message_id']);
        $this->assertSame('coaster', $out['body_style']);
    }

    /** Null is not text. "Was empty, now has something" must stay readable. */
    public function test_a_null_body_stays_null(): void
    {
        $out = $this->redactor->scrub(['body' => null]);

        $this->assertNull($out['body']);
    }

    /**
     * A `body` arriving as an array is still the thing the list exists to keep
     * out, so it is caught before the recursion rather than walked into.
     */
    public function test_a_structured_body_is_not_recursed_into(): void
    {
        $out = $this->redactor->scrub([
            'body' => ['text' => 'On m\'a facture deux fois.', 'lang' => 'fr'],
        ]);

        $this->assertSame(Redactor::REDACTED, $out['body']);
    }

    /* ─────────────────────────── Everything else ─────────────────────────── */

    public function test_ordinary_values_pass_through(): void
    {
        $out = $this->redactor->scrub([
            'status' => 'confirmed',
            'amount' => 500000,
            'assigned_to' => 4,
        ]);

        $this->assertSame(
            ['status' => 'confirmed', 'amount' => 500000, 'assigned_to' => 4],
            $out,
        );
    }

    public function test_nested_payloads_are_scrubbed_too(): void
    {
        $out = $this->redactor->scrub([
            'metadata' => ['api_key' => 'sk_live_x', 'attempt' => 2],
        ]);

        $this->assertSame(Redactor::REDACTED, $out['metadata']['api_key']);
        $this->assertSame(2, $out['metadata']['attempt']);
    }
}
