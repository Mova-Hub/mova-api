<?php

namespace Tests\Feature;

use App\Domain\Messaging\DTOs\SendResult;
use App\Domain\Messaging\MessagingService;
use App\Domain\Messaging\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * One-time codes.
 *
 * Every OTP in the system goes through this service, so the refusals here are
 * the ones standing between a phone number and somebody else's account.
 */
class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(bool $delivers = true): OtpService
    {
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendOtp')->andReturn(
            $delivers
                ? SendResult::sent('sms', 'ref-1')
                : SendResult::failed('sms', 'Twilio down'),
        );

        return new OtpService($messaging);
    }

    public function test_a_code_is_six_digits(): void
    {
        $issued = $this->service()->issue('phone_update', '1', '+242064074926');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        $this->assertTrue($issued['sent']);
    }

    public function test_a_correct_code_verifies_and_returns_its_payload(): void
    {
        $otp = $this->service();

        $issued = $otp->issue('phone_update', '1', '+242064074926', ['phone' => '+242064074926']);

        $this->assertSame(
            ['phone' => '+242064074926'],
            $otp->verify('phone_update', '1', $issued['code']),
        );
    }

    /** A code is spent on first use, so a replay cannot ride it twice. */
    public function test_a_code_works_exactly_once(): void
    {
        $otp = $this->service();
        $issued = $otp->issue('password_reset', '+242064074926', '+242064074926');

        $this->assertNotNull($otp->verify('password_reset', '+242064074926', $issued['code']));
        $this->assertNull($otp->verify('password_reset', '+242064074926', $issued['code']));
    }

    /**
     * The whole point of namespacing by purpose.
     *
     * A password-reset code must not satisfy a phone-update check.
     */
    public function test_a_code_does_not_cross_purposes(): void
    {
        $otp = $this->service();
        $issued = $otp->issue('password_reset', 'shared-key', '+242064074926');

        $this->assertNull($otp->verify('phone_update', 'shared-key', $issued['code']));
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $otp = $this->service();
        $issued = $otp->issue('phone_update', '1', '+242064074926');

        $wrong = $issued['code'] === '000000' ? '111111' : '000000';

        $this->assertNull($otp->verify('phone_update', '1', $wrong));
    }

    /**
     * Five misses burn the code.
     *
     * The route throttle limits how FAST somebody guesses; this limits how many
     * times, which is what matters against a code that lives ten minutes.
     */
    public function test_the_code_dies_after_five_wrong_attempts(): void
    {
        $otp = $this->service();
        $issued = $otp->issue('phone_update', '1', '+242064074926');

        $wrong = $issued['code'] === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($otp->verify('phone_update', '1', $wrong));
        }

        // Even the RIGHT code no longer works.
        $this->assertNull($otp->verify('phone_update', '1', $issued['code']));
    }

    /**
     * A code that could not be sent is not left live.
     *
     * Otherwise the only party who benefits from it is somebody who did not
     * need the SMS to know it.
     */
    public function test_an_undeliverable_code_is_discarded(): void
    {
        $otp = $this->service(delivers: false);

        $issued = $otp->issue('phone_update', '1', '+242064074926');

        $this->assertFalse($issued['sent']);
        $this->assertNull($otp->verify('phone_update', '1', $issued['code']));
    }

    /** Leading zeros survive. The old `(int)` comparison silently dropped them. */
    public function test_a_code_with_a_leading_zero_is_not_equal_to_its_trimmed_form(): void
    {
        $otp = $this->service();

        Cache::put('otp:phone_update:1', ['code' => '012345', 'payload' => [], 'attempts' => 0], 600);

        $this->assertNull($otp->verify('phone_update', '1', '12345'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
