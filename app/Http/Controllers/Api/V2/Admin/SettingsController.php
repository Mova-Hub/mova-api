<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Messaging\MessagingService;
use App\Domain\Settings\Facades\Settings;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Settings page's API.
 *
 * Grouped rather than one flat document: the page loads a tab at a time, and a
 * whole-document PUT would mean two operators on different tabs silently
 * overwriting each other's work.
 *
 * **Secrets never come back out.** A value marked `is_secret` is returned as a
 * masked tail, so an operator can tell which key is stored without the key
 * itself passing back over the wire and into a browser cache, a proxy log, or
 * a screenshot.
 */
class SettingsController extends Controller
{
    /** Groups the page may address. An allow-list, not free-form. */
    private const GROUPS = ['general', 'rules', 'wallet', 'notifications', 'pricing', 'billing'];

    /** Keys whose values are never returned, whatever the group. */
    private const SECRET_SUFFIXES = ['api_key', 'secret', 'token', 'auth_token', 'password'];

    public function __construct(private SettingsRepository $settings) {}

    /** Everything, grouped — one request fills the whole page. */
    public function index()
    {
        $out = [];

        foreach (self::GROUPS as $group) {
            $out[$group] = $this->presentGroup($group);
        }

        return response()->json(['status' => true, 'data' => $out]);
    }

    public function show(string $group)
    {
        abort_unless(in_array($group, self::GROUPS, true), 404);

        return response()->json(['status' => true, 'data' => $this->presentGroup($group)]);
    }

    /**
     * Writes one group.
     *
     * Values are not schema-validated per key on purpose — the settings table
     * is deliberately open, and a hardcoded schema here would put a deploy
     * between ops and a new setting, which is the thing this whole subsystem
     * exists to avoid. The *consumers* validate: `Settings::float()` clamps,
     * `depositShare()` bounds itself to 5–100%.
     *
     * What IS enforced is the group allow-list and the type ceiling below,
     * because those are the difference between a settings store and an
     * arbitrary key/value write primitive on a public-ish endpoint.
     */
    public function update(Request $request, string $group)
    {
        abort_unless(in_array($group, self::GROUPS, true), 404);

        $data = $request->validate([
            'values' => ['required', 'array', 'max:100'],
            'values.*' => ['nullable'],
        ]);

        $userId = $request->user()?->id;

        foreach ($data['values'] as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
                continue;
            }

            /*
             * An empty string against a secret means "leave it alone", not
             * "clear it". The form renders a masked placeholder rather than the
             * value, so submitting the page untouched would otherwise wipe
             * every credential on it.
             */
            $isSecret = $this->isSecretKey($key);

            if ($isSecret && ($value === '' || $value === null)) {
                continue;
            }

            $this->settings->set($group, $key, $value, $isSecret, $userId);
        }

        return response()->json([
            'status' => true,
            'message' => 'Réglages enregistrés.',
            'data' => $this->presentGroup($group),
        ]);
    }

    /**
     * Tests the messaging provider currently configured.
     *
     * Credentials come from the request so a key can be checked BEFORE it is
     * saved — testing only what is already stored means the broken value has to
     * be persisted first.
     */
    public function testMessaging(Request $request, MessagingService $messaging)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['infobip', 'twilio', 'log'])],
            'credentials' => ['nullable', 'array'],
        ]);

        $channel = match ($data['provider']) {
            'infobip' => app(\App\Domain\Messaging\Channels\InfobipChannel::class),
            'twilio' => app(\App\Domain\Messaging\Channels\TwilioChannel::class),
            default => app(\App\Domain\Messaging\Channels\LogChannel::class),
        };

        $result = $channel->healthCheck($data['credentials'] ?? []);

        return response()->json([
            'status' => true,
            'data' => [
                'ok' => $result->ok,
                // `reference` carries the account name or balance on success;
                // `error` carries the reason on failure. Staff-facing, so a
                // provider status code here is useful rather than a leak.
                'message' => $result->ok ? ($result->reference ?? 'Connexion établie.') : $result->error,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGroup(string $group): array
    {
        $stored = Setting::where('group', $group)->get()->keyBy('key');
        $values = $this->settings->group($group);
        $out = [];

        foreach ($values as $key => $value) {
            $isSecret = (bool) ($stored[$key]->is_secret ?? $this->isSecretKey($key));

            $out[$key] = $isSecret
                ? ['is_secret' => true, 'is_set' => filled($value), 'masked' => $this->mask($value)]
                : $value;
        }

        return $out;
    }

    private function isSecretKey(string $key): bool
    {
        foreach (self::SECRET_SUFFIXES as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mask(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Four characters: enough to tell two pasted keys apart, useless to
        // anyone who intercepts the response.
        return str_repeat('•', 8) . substr($value, -4);
    }
}
