<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Pass\Enums\CardStatus;
use App\Domain\Pass\Exceptions\PassException;
use App\Domain\Pass\Services\CardService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pass\Admin\AdminPassCardResource;
use App\Models\Client;
use App\Models\PassCard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Card administration — the counter's HTTP surface.
 *
 * `CardService` already owns every operation here and is verified end to end;
 * PRD §12.4 listed this controller as deliberately deferred until the counter
 * UI existed. It is thin on purpose: **no card logic lives in this file**. The
 * serial generator, the activation invariants, the terminal-block rule and the
 * signing all stay in the service, so the app and the back-office cannot drift
 * apart on what "blocked" means.
 */
class PassCardController extends Controller
{
    public function __construct(private CardService $cards) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(CardStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:64'],
            'client_id' => ['nullable', 'integer'],
        ]);

        $query = PassCard::with('client')->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            // Grouped, so the filters above survive. An ungrouped orWhere at
            // this level discards every constraint before it.
            $query->where(function ($q) use ($search) {
                $q->where('chip_uid', 'like', "%{$search}%")
                  ->orWhere('printed_serial', 'like', '%' . strtoupper($search) . '%')
                  ->orWhereHas('client', fn ($c) => $c
                      ->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        return AdminPassCardResource::collection(
            $query->paginate((int) $request->input('per_page', 25))
        );
    }

    public function show(int $id)
    {
        return new AdminPassCardResource(PassCard::with('client')->findOrFail($id));
    }

    /**
     * Registers a blank chip and returns the payload for the bridge script.
     *
     * This is the counter flow from PRD §5.1. Two things it deliberately does
     * NOT do:
     *
     *  - **It does not sign anything here.** The payload comes back from
     *    `CardService`, which asks `EntitlementSigner`. The private key never
     *    leaves the server and the back-office never holds it.
     *  - **It does not activate the card.** The chip leaves the counter
     *    `encoded` and unowned even when a client is named, which is what makes
     *    a stolen blank batch worthless — the server refuses every one of them
     *    until a real subscriber claims it.
     */
    public function issue(Request $request)
    {
        $data = $request->validate([
            // The factory UID as read by the ACR122U. Hex, no separators.
            'chip_uid' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Fa-f]+$/'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ], [
            'chip_uid.regex' => 'UID de puce invalide.',
        ]);

        $client = isset($data['client_id']) ? Client::find($data['client_id']) : null;

        try {
            $issued = $this->cards->issue(
                strtoupper($data['chip_uid']),
                $client,
                $request->user()->id,
            );
        } catch (PassException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'status' => true,
            'message' => 'Carte enregistrée. Écrivez le contenu ci-dessous sur la puce.',
            'data' => [
                'card' => new AdminPassCardResource($issued['card']->load('client')),
                // Hand this to the bridge script verbatim — it is the exact
                // NDEF URI record that goes on the chip.
                'payload' => $issued['payload'],
            ],
        ], 201);
    }

    /**
     * Issues a replacement and retires the old card.
     *
     * The old one goes to `replaced`, never deleted: it stays on the blacklist
     * export and in the scan history, so a card that turns up later is still
     * recognisable rather than "unknown".
     */
    public function replace(Request $request, int $id)
    {
        $old = PassCard::findOrFail($id);

        $data = $request->validate([
            'chip_uid' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Fa-f]+$/'],
        ]);

        try {
            $issued = $this->cards->replace(
                $old,
                strtoupper($data['chip_uid']),
                $request->user()->id,
            );
        } catch (PassException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'status' => true,
            'message' => 'Carte de remplacement émise.',
            'data' => [
                'card' => new AdminPassCardResource($issued['card']->load('client')),
                'payload' => $issued['payload'],
            ],
        ], 201);
    }

    /**
     * Blocks a card. Terminal — a blocked card is replaced, never un-blocked.
     *
     * Staff get `fraud` as a reason, which the client-facing endpoint
     * deliberately withholds: it is an investigator's determination, and
     * letting a customer self-label would corrupt the reporting.
     */
    public function block(Request $request, int $id)
    {
        $card = PassCard::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', Rule::in(['lost', 'stolen', 'fraud', 'suspended'])],
        ]);

        $card = $this->cards->block($card, $data['reason']);

        return response()->json([
            'status' => true,
            'message' => 'Carte bloquée. Effective à bord dès la prochaine synchronisation des contrôleurs.',
            'data' => new AdminPassCardResource($card->load('client')),
        ]);
    }

    /**
     * Re-signs a card so the chip catches up with the current entitlement.
     *
     * An optimisation, not a requirement: inspectors validate against the
     * server snapshot (PRD decision D2), so a chip carrying a stale expiry is a
     * fallback that is merely out of date, not a card that stops working.
     */
    public function reencode(int $id)
    {
        $card = PassCard::with('client')->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => [
                'card' => new AdminPassCardResource($card),
                'payload' => $this->cards->reencode($card),
            ],
        ]);
    }

    private function fail(PassException $e)
    {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
            'error' => $e->errorCode,
        ], $e->status);
    }
}
