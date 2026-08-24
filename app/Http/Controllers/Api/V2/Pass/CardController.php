<?php

namespace App\Http\Controllers\Api\V2\Pass;

use App\Domain\Pass\Enums\ScanSource;
use App\Domain\Pass\Exceptions\PassException;
use App\Domain\Pass\Services\CardService;
use App\Domain\Pass\Services\ScanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V2\Pass\ActivateCardRequest;
use App\Http\Requests\V2\Pass\BlockCardRequest;
use App\Http\Requests\V2\Pass\RecordScanRequest;
use App\Http\Resources\Pass\PassCardResource;
use App\Http\Resources\Pass\PassScanResource;
use App\Models\PassCard;
use Illuminate\Http\Request;

/**
 * The subscriber's own cards.
 *
 * Every query in this controller starts from `$request->user()->...` or filters
 * on `client_id`. No route takes a card id it then trusts — an id in a request
 * body is an assertion by the caller, not an authorisation.
 */
class CardController extends Controller
{
    public function __construct(
        private CardService $cards,
        private ScanService $scans,
    ) {}

    /** Cards belonging to the authenticated client, newest first. */
    public function index(Request $request)
    {
        $cards = PassCard::where('client_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return PassCardResource::collection($cards);
    }

    /**
     * Binds a card to this account, by tap or by printed serial.
     *
     * Rate-limited at the route. The serial is a guessable-in-principle
     * credential, so an unbounded endpoint here would be a way to hunt for
     * cards to claim.
     */
    public function activate(ActivateCardRequest $request)
    {
        try {
            $card = $this->cards->activate(
                $request->user(),
                $request->input('chip_uid'),
                $request->input('printed_serial'),
            );
        } catch (PassException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'status' => true,
            'message' => 'Carte activée.',
            'data' => new PassCardResource($card),
        ], 200);
    }

    /**
     * Declares a card lost or stolen — the blacklist entry (PC-1).
     *
     * Effective immediately server-side. Inspectors pick it up on their next
     * sync, which PRD §4.5 accepts as a window of up to 24 hours; that is an
     * operational limit of offline verification, not something this endpoint
     * can shorten.
     *
     * Terminal by design. A blocked card is replaced, never un-blocked: undoing
     * it would let a thief who returns a card put it back into circulation, and
     * would make the blacklist a moving target for readers that cannot re-check.
     */
    public function block(BlockCardRequest $request, int $id)
    {
        // Scoped, not `findOrFail($id)` — otherwise any authenticated client
        // could block a stranger's card by guessing an integer.
        $card = PassCard::where('client_id', $request->user()->id)->findOrFail($id);

        $card = $this->cards->block($card, $request->string('reason')->toString());

        return response()->json([
            'status' => true,
            'message' => 'Carte bloquée. Elle ne peut plus être utilisée.',
            'data' => new PassCardResource($card),
        ]);
    }

    /**
     * Records the subscriber reading their own card.
     *
     * Logged like any other scan, but with `source: app`, which keeps it out of
     * the boarding figures — someone checking their card at home is not a trip,
     * and counting it would inflate every ridership number the business plans
     * against.
     */
    public function scan(RecordScanRequest $request)
    {
        $result = $this->scans->record(
            chipUid: $request->input('chip_uid'),
            payload: $request->input('payload'),
            source: ScanSource::App,
            context: [
                'client_reference' => $request->input('client_reference'),
                'device_id' => $request->input('device_id'),
                'scanned_at' => $request->input('scanned_at'),
            ],
        );

        return response()->json([
            'status' => true,
            'data' => [
                'verdict' => $result['verdict']->value,
                'verdict_label' => $result['verdict']->label(),
                'tone' => $result['verdict']->tone(),
                'card' => $result['card'] ? new PassCardResource($result['card']) : null,
                'scan' => new PassScanResource($result['scan']),
            ],
        ], 201);
    }

    /** The subscriber's own scan history. */
    /**
     * The subscriber's own boarding history.
     *
     * Paginated rather than a flat `limit(100)`. The old cap was silent: a
     * daily commuter passes a hundred boardings in under two months, and from
     * then on the screen showed a truncated history with nothing to say so —
     * the rows simply stopped, which reads as "Mova lost my trips".
     *
     * `per_page` is clamped. An unbounded value here is a way to pull an entire
     * scan table in one request, and a zero divides by nothing in the paginator.
     */
    public function history(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $scans = \App\Models\PassScan::where('client_id', $request->user()->id)
            // `scanned_at` is when the passenger boarded; `id` breaks ties for
            // the offline batches that upload with identical timestamps. Without
            // the tiebreaker those rows shuffle between pages and the same
            // boarding can appear twice, or not at all.
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return PassScanResource::collection($scans);
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
