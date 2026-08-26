<?php

namespace App\Console\Commands;

use App\Models\PaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Creates the sandbox API User and API Key for MTN MoMo.
 *
 * **This step exists ONLY in the sandbox.** In production MTN provisions the
 * API user for you as part of onboarding and hands over the credentials; there
 * is no self-service endpoint. In the sandbox you must create them yourself,
 * and the sequence is the single most common place an MTN integration stalls:
 *
 *   1. Subscribe to the **Collections** product on momodeveloper.mtn.com.
 *      That gives you the `Ocp-Apim-Subscription-Key` (its "Primary Key").
 *   2. `POST /v1_0/apiuser` with an `X-Reference-Id` you generate. That UUID
 *      *becomes* the API user — the endpoint returns 201 and an EMPTY body,
 *      which is why people think it failed.
 *   3. `POST /v1_0/apiuser/{apiUser}/apikey` returns the API key. It is shown
 *      **once**; there is no endpoint to read it back.
 *   4. `POST /collection/token/` with Basic auth `apiUser:apiKey` yields the
 *      bearer token every other call needs.
 *
 * Steps 2–4 are what this command does, storing the result in the provider's
 * encrypted credential bag so nothing is copied by hand or left in a shell
 * history.
 *
 * Refuses to touch a provider in `live` mode: these endpoints do not exist
 * there, and pointing the production record at sandbox credentials would take
 * real payments offline.
 */
class ProvisionMomoSandbox extends Command
{
    protected $signature = 'momo:provision
        {--subscription-key= : Collections primary key from momodeveloper.mtn.com}
        {--callback-host= : Host only, no scheme or path (e.g. api.mova-mobility.com)}
        {--force : Replace an API user that is already stored}';

    protected $description = 'Create the MTN MoMo sandbox API user and key, and store them';

    public function handle(): int
    {
        $provider = PaymentProvider::where('code', 'mtn_momo')->first();

        if (! $provider) {
            $this->error('No `mtn_momo` provider row. Run: php artisan db:seed --class=PaymentProviderSeeder');

            return self::FAILURE;
        }

        if ($provider->mode === 'live') {
            $this->error('This provider is in LIVE mode.');
            $this->line('  Sandbox provisioning endpoints do not exist in production, and writing');
            $this->line('  sandbox credentials here would take real payments offline.');
            $this->line('  Switch it to test mode first, or provision a separate record.');

            return self::FAILURE;
        }

        $credentials = $provider->credentials ?? [];

        $subscriptionKey = (string) ($this->option('subscription-key')
            ?: $credentials['subscription_key']
            ?? $this->secret('Collections primary key (Ocp-Apim-Subscription-Key)'));

        if ($subscriptionKey === '') {
            $this->error('A subscription key is required. Find it under your Collections product on momodeveloper.mtn.com.');

            return self::FAILURE;
        }

        if (! empty($credentials['api_user']) && ! $this->option('force')) {
            $this->warn('An API user is already stored: ' . $credentials['api_user']);
            $this->line('  Re-run with --force to replace it. The old one keeps working; MTN does');
            $this->line('  not revoke it, so nothing breaks either way.');

            return self::SUCCESS;
        }

        $baseUrl = rtrim((string) config('payment.endpoints.mtn_momo.test'), '/');

        /*
         * The callback host is a HOST, not a URL.
         *
         * MTN rejects anything with a scheme or a path — "https://x.com/hook"
         * fails validation with a message that does not say why. It is also
         * only advisory in the sandbox: callbacks are not reliably delivered
         * there, which is exactly why the driver polls rather than waiting.
         */
        $callbackHost = (string) ($this->option('callback-host')
            ?: parse_url((string) config('app.url'), PHP_URL_HOST)
            ?: 'localhost');

        $apiUser = (string) Str::uuid();

        $this->line('');
        $this->info('MTN MoMo — sandbox provisioning');
        $this->line('  Endpoint      ' . $baseUrl);
        $this->line('  Callback host ' . $callbackHost);
        $this->line('  API user      ' . $apiUser);
        $this->line('');

        // ── 1. Create the API user ────────────────────────────────────────
        $create = Http::timeout(20)
            ->withHeaders([
                'X-Reference-Id' => $apiUser,
                'Ocp-Apim-Subscription-Key' => $subscriptionKey,
                'Content-Type' => 'application/json',
            ])
            ->post($baseUrl . '/v1_0/apiuser', ['providerCallbackHost' => $callbackHost]);

        // 201 with an empty body is SUCCESS here. 409 means the UUID is taken,
        // which cannot happen with a fresh one unless something else raced us.
        if ($create->status() !== 201) {
            $this->error('Creating the API user failed (HTTP ' . $create->status() . ').');
            $this->line('  ' . ($create->body() ?: '(empty body)'));
            $this->line('');
            $this->line($create->status() === 401
                ? '  A 401 here almost always means the subscription key is wrong, or you'
                  . PHP_EOL . '  subscribed to Disbursements rather than Collections.'
                : '  Check the key and that the Collections subscription is active.');

            return self::FAILURE;
        }

        $this->line('  ✓ API user created');

        // ── 2. Mint the API key ───────────────────────────────────────────
        $keyResponse = Http::timeout(20)
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])
            ->post($baseUrl . '/v1_0/apiuser/' . $apiUser . '/apikey');

        if (! $keyResponse->successful() || ! $keyResponse->json('apiKey')) {
            $this->error('Creating the API key failed (HTTP ' . $keyResponse->status() . ').');
            $this->line('  ' . ($keyResponse->body() ?: '(empty body)'));

            return self::FAILURE;
        }

        $apiKey = (string) $keyResponse->json('apiKey');
        $this->line('  ✓ API key issued');

        // ── 3. Prove it works before storing it ───────────────────────────
        $token = Http::timeout(20)
            ->withBasicAuth($apiUser, $apiKey)
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])
            ->post($baseUrl . '/collection/token/');

        if (! $token->successful() || ! $token->json('access_token')) {
            $this->error('The credentials were created but will not mint a token (HTTP ' . $token->status() . ').');
            $this->line('  Nothing has been saved. This usually means the subscription key belongs');
            $this->line('  to a different product than the one the API user was created under.');

            return self::FAILURE;
        }

        $this->line('  ✓ Token obtained — the credentials work');

        /*
         * Written only after the round trip succeeds.
         *
         * Storing first and testing after is how a provider ends up holding
         * credentials that have never worked, with the failure surfacing later
         * on a customer's first payment attempt.
         */
        $provider->credentials = array_merge($credentials, [
            'subscription_key' => $subscriptionKey,
            'api_user' => $apiUser,
            'api_key' => $apiKey,
            'target_environment' => 'sandbox',
        ]);
        $provider->save();

        $this->line('');
        $this->info('Stored on the `mtn_momo` provider (encrypted at rest).');
        $this->line('');
        $this->line('  The API key is shown once by MTN and cannot be read back. It is now only');
        $this->line('  in the database. If you need a copy, take it from this run:');
        $this->line('');
        $this->line('    api_user : ' . $apiUser);
        $this->line('    api_key  : ' . $apiKey);
        $this->line('');
        $this->line('  Next: php artisan momo:doctor --pay=46733123450');
        $this->line('');
        $this->warn('  The provider is still disabled. Enable it in Réglages → Paiement when ready.');

        return self::SUCCESS;
    }
}
