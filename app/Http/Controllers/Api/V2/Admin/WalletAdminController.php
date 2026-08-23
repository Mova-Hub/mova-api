<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Wallet\Enums\WalletReason;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mova Credit, from the back-office.
 *
 * **Read MOVA-WALLET-AND-PAYMENTS.md §3 before adding an endpoint here.**
 *
 * There is no top-up route, no cash-out route and no transfer route, and their
 * absence is the compliance posture rather than an oversight. Staff may GRANT
 * credit — money Mova gives away — and they may freeze an account. They may not
 * accept customer funds, which is what would make this electronic money under
 * Règlement 04/18/CEMAC/UMAC/COBAC.
 *
 * Every grant is capped, requires a reason, and is written to the audit trail
 * through the WalletEntry observer. Credit is money leaving the business; it
 * should be as attributable as a refund.
 */
class WalletAdminController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function index(Request $request)
    {
        $accounts = WalletAccount::query()
            ->with('client:id,name,phone')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search') . '%';
                $q->whereHas('client', fn ($c) => $c
                    ->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->boolean('with_balance'), fn ($q) => $q->where('balance', '>', 0))
            ->orderByDesc('balance')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'status' => true,
            'data' => $accounts->through(fn (WalletAccount $a) => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'client_name' => $a->client?->name,
                'balance' => $a->balance,
                'currency' => $a->currency,
                'status' => $a->status,
                'updated_at' => $a->updated_at?->toIso8601String(),
            ]),
            'meta' => [
                // The total outstanding liability. Worth showing on the tab
                // header: it is the number a finance conversation starts from.
                'total_outstanding' => (int) WalletAccount::sum('balance'),
            ],
        ]);
    }

    /** One account, with its ledger. */
    public function show(int $clientId)
    {
        $client = Client::findOrFail($clientId);
        $account = $this->wallet->accountFor($client);

        return response()->json([
            'status' => true,
            'data' => [
                'client' => ['id' => $client->id, 'name' => $client->name],
                'balance' => $account->balance,
                'currency' => $account->currency,
                'status' => $account->status,
                'entries' => $account->entries()->limit(100)->get()->map(fn (WalletEntry $e) => [
                    'uuid' => $e->uuid,
                    'direction' => $e->direction,
                    'amount' => $e->amount,
                    'balance_after' => $e->balance_after,
                    'reason' => $e->reason->value,
                    'reason_label' => $e->reason->label(),
                    'note' => $e->note,
                    'expires_at' => $e->expires_at?->toIso8601String(),
                    'created_at' => $e->created_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * Grants credit.
     *
     * The reason list is restricted to what a human may legitimately award —
     * `refund_issued` and `spend_reversed` are system-generated and would let
     * an operator fabricate a refund that never happened.
     */
    public function grant(Request $request, int $clientId)
    {
        $client = Client::findOrFail($clientId);

        $grantable = collect(WalletReason::cases())
            ->filter(fn (WalletReason $r) => $r->isManuallyGrantable() && $r->isCredit())
            ->map(fn (WalletReason $r) => $r->value)
            ->values()
            ->all();

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in($grantable)],
            // Required, not optional. An unexplained credit is indistinguishable
            // from an error six months later, and this is the field the audit
            // trail carries.
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [
            'note.required' => 'Indiquez le motif de ce crédit.',
        ]);

        try {
            $entry = $this->wallet->grant(
                client: $client,
                amount: $data['amount'],
                reason: WalletReason::from($data['reason']),
                note: $data['note'],
                expiresAt: isset($data['expires_at']) ? \Carbon\CarbonImmutable::parse($data['expires_at']) : null,
                grantedBy: $request->user()?->id,
            );
        } catch (WalletException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Crédit accordé.',
            'data' => ['uuid' => $entry->uuid, 'balance' => $entry->balance_after],
        ], 201);
    }

    /**
     * Freezes or unfreezes an account.
     *
     * Blocks SPENDING only — the ledger keeps accepting entries, so a refund
     * owed to a frozen account still lands and is there when the freeze lifts.
     */
    public function setStatus(Request $request, int $clientId)
    {
        $client = Client::findOrFail($clientId);

        $data = $request->validate(['status' => ['required', Rule::in(['active', 'frozen'])]]);

        $account = $this->wallet->accountFor($client);
        $account->update(['status' => $data['status']]);

        return response()->json([
            'status' => true,
            'message' => $data['status'] === 'frozen' ? 'Solde gelé.' : 'Solde réactivé.',
        ]);
    }

    /**
     * Re-derives every balance from the ledger and reports drift.
     *
     * Reports by default; repairs only when asked. Silently correcting would
     * hide the bug that caused the drift, and drift in a money ledger deserves
     * a person's attention.
     */
    public function reconcile(Request $request)
    {
        $repair = $request->boolean('repair');
        $drift = $this->wallet->reconcile($repair);

        return response()->json([
            'status' => true,
            'data' => ['drift' => $drift, 'repaired' => $repair, 'count' => count($drift)],
            'message' => $drift === []
                ? 'Tous les soldes correspondent au grand livre.'
                : count($drift) . ' solde(s) divergent du grand livre.',
        ]);
    }
}
