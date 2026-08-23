<?php

namespace App\Http\Controllers\Api\V2\Payment;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\WalletEntry;
use Illuminate\Http\Request;

/**
 * Mova Credit, as the client sees it.
 *
 * **Read only, deliberately and permanently.**
 *
 * There is no `store`, no `topUp`, no `withdraw` and no `transfer` here, and
 * their absence is the compliance boundary rather than an unfinished feature.
 * A route that increases a balance in exchange for customer funds would make
 * Mova an issuer of electronic money under Règlement 04/18/CEMAC/UMAC/COBAC,
 * which requires a licence roughly seven organisations in the CEMAC zone hold.
 *
 * See MOVA-WALLET-AND-PAYMENTS.md §3.3. Credit is SPENT through the normal
 * payment routes with provider `mova_credit`, so it flows through the same
 * state machine and lands in the same ledger as any other payment.
 */
class WalletController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function show(Request $request)
    {
        $client = $request->user();
        $account = $this->wallet->accountFor($client);

        // The next credit to lapse, so the app can warn rather than let it
        // vanish silently — promotional credit disappearing with no notice is
        // how a goodwill gesture becomes a complaint.
        $expiring = WalletEntry::where('wallet_account_id', $account->id)
            ->where('direction', 'credit')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->first();

        return response()->json([
            'status' => true,
            'data' => [
                'balance' => $account->balance,
                'currency' => $account->currency,
                'is_active' => $account->isSpendable(),
                'expiring_amount' => $expiring?->amount,
                'expiring_at' => $expiring?->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /** The ledger, newest first. */
    public function entries(Request $request)
    {
        $account = $this->wallet->accountFor($request->user());

        $entries = WalletEntry::where('wallet_account_id', $account->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30));

        return response()->json([
            'status' => true,
            'data' => $entries->through(fn (WalletEntry $e) => [
                'uuid' => $e->uuid,
                'direction' => $e->direction,
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
                'reason' => $e->reason->value,
                'label' => $e->reason->label(),
                // `note` can carry an internal remark from an agent, so only
                // the reason label is shown — a client should not read
                // "geste commercial suite à réclamation, client difficile".
                'created_at' => $e->created_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
            ]),
        ]);
    }
}
