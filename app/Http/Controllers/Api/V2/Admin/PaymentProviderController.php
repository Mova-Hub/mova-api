<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Payment\PaymentDriverRegistry;
use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Managing payment methods from Settings → Paiement.
 *
 * This controller plus a class implementing PaymentDriver is the whole
 * "add a provider without a deploy" story. See MOVA-WALLET-AND-PAYMENTS.md §5.4.
 *
 * **Credentials go in and never come out.** Reads return `has_credentials` and
 * a masked tail; a full value is never serialised, never logged, and redacted
 * in the audit trail. The `credentials` cast encrypts them at rest, so a
 * database dump on someone's laptop carries no usable API keys.
 */
class PaymentProviderController extends Controller
{
    public function __construct(private PaymentDriverRegistry $registry) {}

    public function index()
    {
        $providers = PaymentProvider::ordered()->get()->map(fn ($p) => $this->present($p));

        return response()->json([
            'status' => true,
            'data' => $providers,
            // The drivers available to attach a new provider to. Read from
            // config so the page cannot offer a driver that does not exist.
            'meta' => ['drivers' => array_keys(config('payment.drivers', []))],
        ]);
    }

    public function show(int $id)
    {
        return response()->json([
            'status' => true,
            'data' => $this->present(PaymentProvider::findOrFail($id)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $provider = PaymentProvider::create($data);

        return response()->json([
            'status' => true,
            'message' => 'Moyen de paiement créé.',
            'data' => $this->present($provider),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $provider = PaymentProvider::findOrFail($id);
        $data = $this->validated($request, $provider);

        /*
         * Credentials MERGE rather than replace.
         *
         * The form renders masked placeholders, not values, so a submit that
         * touched only the fee would otherwise arrive with every credential
         * blank and wipe the lot. Blank means "unchanged"; clearing one is a
         * deliberate `null`.
         */
        if (array_key_exists('credentials', $data)) {
            $incoming = array_filter(
                $data['credentials'] ?? [],
                fn ($v) => $v !== '' && $v !== null,
            );

            $data['credentials'] = array_merge($provider->credentials ?? [], $incoming);
        }

        $provider->update($data);

        return response()->json([
            'status' => true,
            'message' => 'Moyen de paiement mis à jour.',
            'data' => $this->present($provider->refresh()),
        ]);
    }

    /**
     * Enables or disables, as its own endpoint.
     *
     * Separate from `update` because it is the one control an operator reaches
     * for in a hurry — a provider misbehaving at 9am should be one toggle away
     * from off, not a form submit that also re-validates fees and limits.
     */
    public function toggle(Request $request, int $id)
    {
        $provider = PaymentProvider::findOrFail($id);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        // Enabling something that cannot collect would put a row in the app
        // that fails on every tap. The card stub is exactly this case.
        if ($data['enabled']) {
            try {
                if (! $this->registry->driverFor($provider)->capabilities()->collect) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Ce pilote ne peut pas encore encaisser. Il resterait indisponible dans l’application.',
                    ], 422);
                }
            } catch (Throwable $e) {
                return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
            }
        }

        $provider->update(['enabled' => $data['enabled']]);

        return response()->json([
            'status' => true,
            'message' => $data['enabled'] ? 'Moyen de paiement activé.' : 'Moyen de paiement désactivé.',
            'data' => $this->present($provider->refresh()),
        ]);
    }

    /**
     * The "Tester" button.
     *
     * Takes credentials from the request so a key can be checked before it is
     * saved. Falls back to the stored ones when the form sends none, which is
     * the "is it still working?" case rather than the "did I paste it right?"
     * case.
     */
    public function test(Request $request, int $id)
    {
        $provider = PaymentProvider::findOrFail($id);

        $data = $request->validate(['credentials' => ['nullable', 'array']]);

        $credentials = array_merge(
            $provider->credentials ?? [],
            array_filter($data['credentials'] ?? [], fn ($v) => $v !== '' && $v !== null),
        );

        try {
            $result = $this->registry->driverFor($provider)->healthCheck($credentials);
        } catch (Throwable $e) {
            return response()->json([
                'status' => true,
                'data' => ['ok' => false, 'message' => $e->getMessage()],
            ]);
        }

        // Recorded so the index can show when each provider was last known
        // good — the question an operator actually has at 9am.
        $provider->forceFill([
            'last_checked_at' => now(),
            'last_check_status' => $result->ok ? 'ok' : 'failed',
        ])->saveQuietly();

        return response()->json(['status' => true, 'data' => $result->toArray()]);
    }

    /** Uploads the logo the mobile app renders. */
    public function uploadLogo(Request $request, int $id)
    {
        $provider = PaymentProvider::findOrFail($id);

        $request->validate([
            // Tight: this file is served to every app user, so it is both a
            // bandwidth cost and, if the mime check is loose, an upload
            // primitive. SVG is excluded deliberately — it can carry script.
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=1024,max_height=1024'],
        ]);

        // Replaces rather than accumulates: a provider has one logo, and
        // orphaned uploads are storage nobody ever reclaims.
        if ($provider->logo_path) {
            Storage::disk('public')->delete($provider->logo_path);
        }

        $path = $request->file('logo')->store('payment-providers', 'public');

        $provider->update(['logo_path' => $path]);

        return response()->json([
            'status' => true,
            'message' => 'Logo mis à jour.',
            'data' => $this->present($provider->refresh()),
        ]);
    }

    public function destroy(int $id)
    {
        $provider = PaymentProvider::findOrFail($id);

        /*
         * Refused when payments reference it.
         *
         * `payments.provider_code` is a string, not a foreign key, so deleting
         * would not error — it would silently orphan every historical payment
         * made through this provider, leaving blank rows in clients' history
         * and nothing to reconcile a statement against. Disable it instead.
         */
        if ($provider->payments()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Ce moyen de paiement a un historique. Désactivez-le plutôt que de le supprimer.',
            ], 422);
        }

        if ($provider->logo_path) {
            Storage::disk('public')->delete($provider->logo_path);
        }

        $provider->delete();

        return response()->json(['status' => true, 'message' => 'Moyen de paiement supprimé.']);
    }

    /* ─────────────────────────── Internals ─────────────────────────── */

    private function validated(Request $request, ?PaymentProvider $existing = null): array
    {
        return $request->validate([
            'code' => [
                $existing ? 'sometimes' : 'required',
                'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('payment_providers', 'code')->ignore($existing?->id),
            ],
            'driver' => [
                $existing ? 'sometimes' : 'required',
                Rule::in(array_keys(config('payment.drivers', []))),
            ],
            'label' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2048'],
            'fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'fee_fixed' => ['sometimes', 'integer', 'min:0'],
            'fee_bearer' => ['sometimes', Rule::in(['client', 'merchant'])],
            'min_amount' => ['sometimes', 'integer', 'min:0'],
            'max_amount' => ['nullable', 'integer', 'min:1'],
            'currencies' => ['sometimes', 'array'],
            'currencies.*' => ['string', 'size:3'],
            'countries' => ['sometimes', 'array'],
            'countries.*' => ['string', 'size:2'],
            'phone_prefixes' => ['sometimes', 'array'],
            'phone_prefixes.*' => ['string', 'max:6'],
            'fields' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ], [
            'code.regex' => 'Le code ne peut contenir que des minuscules, chiffres et underscores.',
            'fee_percent.max' => 'Les frais ne peuvent pas dépasser 100 %.',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(PaymentProvider $provider): array
    {
        $capabilities = [];

        try {
            $capabilities = $this->registry->driverFor($provider)->capabilities()->toArray();
        } catch (Throwable) {
            // A row pointing at a missing driver still has to render, or the
            // page an operator would use to fix it cannot open.
        }

        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'driver' => $provider->driver,
            'label' => $provider->label,
            'description' => $provider->description,
            'logo_url' => $provider->logoUrl(),
            'brand_color' => $provider->brand_color,
            'enabled' => $provider->enabled,
            'mode' => $provider->mode,

            // NEVER the values. See the class docblock.
            'has_credentials' => $provider->hasCredentials(),
            'credential_hints' => $provider->maskedCredentials(),

            'fee_percent' => (float) $provider->fee_percent,
            'fee_fixed' => (int) $provider->fee_fixed,
            'fee_bearer' => $provider->fee_bearer,
            'min_amount' => (int) $provider->min_amount,
            'max_amount' => $provider->max_amount,
            'currencies' => $provider->currencies ?: [],
            'countries' => $provider->countries ?: [],
            'phone_prefixes' => $provider->phone_prefixes ?: [],
            'fields' => $provider->fields ?: [],
            'capabilities' => $capabilities,
            'sort_order' => (int) $provider->sort_order,

            'last_checked_at' => $provider->last_checked_at?->toIso8601String(),
            'last_check_status' => $provider->last_check_status,
        ];
    }
}
