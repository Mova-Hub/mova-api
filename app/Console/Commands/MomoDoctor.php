<?php

namespace App\Console\Commands;

use App\Models\PaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Checks an MTN MoMo integration, one layer at a time.
 *
 * The reason this exists rather than "just try a payment": MoMo returns 401 for
 * at least four unrelated causes — wrong subscription key, wrong API user,
 * wrong API key, wrong `X-Target-Environment` — and the body says nothing about
 * which. Testing through the app gives you one opaque failure at the end of a
 * five-step chain. This walks the chain and stops at the first broken link.
 *
 * With `--pay` it also runs a real sandbox collection and polls it to a final
 * state, which is the only way to see the asynchronous half working.
 */
class MomoDoctor extends Command
{
    protected $signature = 'momo:doctor
        {--pay= : MSISDN to charge, e.g. 46733123450 (MTN\'s sandbox test number)}
        {--amount=100 : Amount to request}
        {--poll=8 : How many times to poll for a final status}';

    protected $description = 'Diagnose the MTN MoMo integration layer by layer';

    public function handle(): int
    {
        $provider = PaymentProvider::where('code', 'mtn_momo')->first();

        if (! $provider) {
            $this->error('No `mtn_momo` provider row. Run: php artisan db:seed --class=PaymentProviderSeeder');

            return self::FAILURE;
        }

        $mode = $provider->mode === 'live' ? 'live' : 'test';
        $baseUrl = rtrim((string) config("payment.endpoints.mtn_momo.{$mode}"), '/');
        $credentials = $provider->credentials ?? [];

        $this->line('');
        $this->info('MTN MoMo — diagnostic');
        $this->line('  Mode      ' . strtoupper($mode) . ($provider->enabled ? ' · enabled' : ' · DISABLED'));
        $this->line('  Endpoint  ' . $baseUrl);
        $this->line('');

        // ── 1. Credentials present ────────────────────────────────────────
        $required = ['subscription_key', 'api_user', 'api_key'];
        $missing = array_values(array_filter($required, fn ($k) => empty($credentials[$k])));

        if ($missing !== []) {
            $this->error('✗ Missing credentials: ' . implode(', ', $missing));
            $this->line('');
            $this->line($mode === 'test'
                ? '  Run: php artisan momo:provision'
                : '  Production credentials come from MTN onboarding — there is no self-service.');

            return self::FAILURE;
        }

        $this->line('  ✓ Credentials present');

        $targetEnvironment = $credentials['target_environment']
            ?? ($mode === 'live' ? 'mtncongo' : 'sandbox');

        /*
         * A mismatch here is subtle and worth calling out.
         *
         * `X-Target-Environment` must match the environment the API user was
         * created in. A sandbox user with `mtncongo` set — or the reverse —
         * yields a 401 identical to a wrong password.
         */
        if ($mode === 'test' && $targetEnvironment !== 'sandbox') {
            $this->warn('  ! target_environment is "' . $targetEnvironment . '" on a TEST provider.');
            $this->line('    The sandbox only accepts "sandbox". This will 401.');
        }

        // ── 2. Token ──────────────────────────────────────────────────────
        $tokenResponse = Http::timeout(20)
            ->withBasicAuth((string) $credentials['api_user'], (string) $credentials['api_key'])
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $credentials['subscription_key']])
            ->post($baseUrl . '/collection/token/');

        if (! $tokenResponse->successful() || ! $tokenResponse->json('access_token')) {
            $this->error('✗ Token request failed (HTTP ' . $tokenResponse->status() . ')');
            $this->line('  ' . ($tokenResponse->body() ?: '(empty body)'));
            $this->line('');
            $this->line('  A 401 here means one of: the subscription key is not the Collections one,');
            $this->line('  the API user/key pair is wrong, or they were created against a different');
            $this->line('  product. Provisioning again is cheap: php artisan momo:provision --force');

            return self::FAILURE;
        }

        $token = (string) $tokenResponse->json('access_token');
        $this->line('  ✓ Token obtained (expires in ' . ($tokenResponse->json('expires_in') ?? '?') . 's)');

        // ── 3. An authenticated read ──────────────────────────────────────
        $balance = Http::timeout(20)
            ->withToken($token)
            ->withHeaders([
                'X-Target-Environment' => $targetEnvironment,
                'Ocp-Apim-Subscription-Key' => $credentials['subscription_key'],
            ])
            ->get($baseUrl . '/collection/v1_0/account/balance');

        if ($balance->successful()) {
            $this->line('  ✓ Account reachable — balance ' . ($balance->json('availableBalance') ?? '?')
                . ' ' . ($balance->json('currency') ?? ''));
        } else {
            // Not fatal: some sandbox subscriptions do not expose balance, and
            // collection still works. Said plainly rather than failing the run.
            $this->warn('  ! Balance unavailable (HTTP ' . $balance->status() . '). Not fatal — '
                . 'collection can still work.');
        }

        if (! $this->option('pay')) {
            $this->line('');
            $this->info('Auth chain is healthy. Add --pay=46733123450 to run a real collection.');
            $this->line('');

            return self::SUCCESS;
        }

        // ── 4. A real collection ──────────────────────────────────────────
        $reference = (string) Str::uuid();
        $amount = (string) (int) $this->option('amount');

        /*
         * The sandbox settles in EUR whatever you send.
         *
         * XAF is correct in production and rejected here, which is the classic
         * first-integration failure — the driver already encodes this rule.
         */
        $currency = $mode === 'live' ? 'XAF' : 'EUR';

        $this->line('');
        $this->info('Requesting payment');
        $this->line('  Reference ' . $reference);
        $this->line('  Amount    ' . $amount . ' ' . $currency);
        $this->line('  Payer     ' . $this->option('pay'));
        $this->line('');

        $charge = Http::timeout(30)
            ->withToken($token)
            ->withHeaders([
                'X-Reference-Id' => $reference,
                'X-Target-Environment' => $targetEnvironment,
                'Ocp-Apim-Subscription-Key' => $credentials['subscription_key'],
                'Content-Type' => 'application/json',
            ])
            ->post($baseUrl . '/collection/v1_0/requesttopay', [
                'amount' => $amount,
                'currency' => $currency,
                'externalId' => (string) time(),
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => (string) $this->option('pay')],
                'payerMessage' => 'Mova diagnostic',
                'payeeNote' => 'Mova diagnostic',
            ]);

        // 202 Accepted with an empty body is the success case. There is no id
        // in the response — the reference we generated IS the transaction id.
        if ($charge->status() !== 202) {
            $this->error('✗ requestToPay failed (HTTP ' . $charge->status() . ')');
            $this->line('  ' . ($charge->body() ?: '(empty body)'));

            return self::FAILURE;
        }

        $this->line('  ✓ Accepted (202). MTN has queued the prompt.');
        $this->line('');

        // ── 5. Poll to a final state ──────────────────────────────────────
        $attempts = max(1, (int) $this->option('poll'));

        for ($i = 1; $i <= $attempts; $i++) {
            sleep(2);

            $status = Http::timeout(20)
                ->withToken($token)
                ->withHeaders([
                    'X-Target-Environment' => $targetEnvironment,
                    'Ocp-Apim-Subscription-Key' => $credentials['subscription_key'],
                ])
                ->get($baseUrl . '/collection/v1_0/requesttopay/' . $reference);

            if (! $status->successful()) {
                $this->warn('  poll ' . $i . ': HTTP ' . $status->status());

                continue;
            }

            $state = (string) $status->json('status');
            $this->line('  poll ' . $i . ': ' . $state
                . ($status->json('reason') ? ' — ' . json_encode($status->json('reason')) : ''));

            if ($state !== 'PENDING') {
                $this->line('');
                $state === 'SUCCESSFUL'
                    ? $this->info('Collection SUCCESSFUL. The full loop works.')
                    : $this->warn('Final state: ' . $state . '. The loop works; this payer/amount was refused.');
                $this->line('');

                return self::SUCCESS;
            }
        }

        $this->line('');
        $this->warn('Still PENDING after ' . $attempts . ' polls.');
        $this->line('  Not a failure — this is exactly the asynchrony the driver is built around.');
        $this->line('  In the app the payment stays "en cours" and `payments:reconcile` settles it.');
        $this->line('');

        return self::SUCCESS;
    }
}
