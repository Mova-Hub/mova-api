<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Enums\ExpenseMethod;
use App\Domain\Finance\Exceptions\ExpenseException;
use App\Domain\Finance\ExpenseService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\ExpenseResource;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Dépenses: the money-out half of the ledger.
 *
 * Admin-only, matching the dashboard and Réglages rather than the Paiements
 * screen. Confirming that a client's payment arrived is agent work; seeing what
 * the company spends, on whom, and what that leaves is not, and the API is
 * where that line has to hold because the sidebar only hides doors.
 *
 * There is NO update route and NO delete route, and their absence is the
 * design. A recorded expense is corrected by POSTing a reversal, so the history
 * of a mistake and its correction both survive. The only mutation offered is
 * attaching the receipt, which changes the documentation rather than the
 * figures; see Expense::MUTABLE.
 */
class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    /**
     * The list, plus the totals for the same filter.
     *
     * The `summary` block rides along with the rows rather than coming from a
     * second endpoint, because it must describe exactly the rows being shown. A
     * separate call takes its own parameters, and the first time those drift the
     * screen shows a breakdown that does not add up to the list beneath it.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'method' => ['nullable', Rule::enum(ExpenseMethod::class)],
            'bus_id' => ['nullable', 'integer', 'exists:buses,id'],
            'reservation_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $rows = $this->expenses->filtered($filters)
            ->paginate($this->perPage($request, 50));

        /*
         * The window the totals describe.
         *
         * Defaulted to the current month when the caller gave no dates, not to
         * all time: an unbounded sum over a growing table is both slow and
         * meaningless as a header figure, and "this month" is what somebody
         * opening the page is asking about.
         */
        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'])
            : CarbonImmutable::now()->startOfMonth();

        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'])
            : CarbonImmutable::now()->endOfMonth();

        /*
         * `additional()` rather than a hand-built envelope, so the paginator
         * keeps writing `meta.current_page` and friends itself. Building that
         * block by hand is how a list endpoint ends up reporting a `last_page`
         * that does not match the rows it just served.
         */
        return ExpenseResource::collection($rows)->additional([
            'status' => true,
            'summary' => $this->expenses->summary($from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /**
     * What the entry form offers.
     *
     * Served rather than hardcoded in the client so the category list, its
     * direct/overhead split and the "this one wants a plate" hint have one
     * definition. A copy in TypeScript would be a second source of truth for
     * the axis every finance report is drawn against.
     */
    public function options()
    {
        return response()->json([
            'status' => true,
            'data' => [
                'categories' => ExpenseCategory::options(),
                'methods' => ExpenseMethod::options(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $expense = Expense::with(['bus:id,plate,name', 'recorder:id,name', 'reverses'])
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => new ExpenseResource($expense),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(ExpenseMethod::class)],
            /*
             * Required, and `before_or_equal:today` is enforced again in the
             * service. Duplicated deliberately: this gives a field-level error
             * on the right input, the service guarantees the rule for every
             * future caller that is not this form.
             */
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'bus_id' => ['nullable', 'integer', 'exists:buses,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
            // Required, same reasoning as a wallet grant: an unexplained
            // movement of money is indistinguishable from a mistake later.
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'note.required' => 'Indiquez la nature de cette dépense.',
            'paid_at.before_or_equal' => 'La date de paiement ne peut pas être dans le futur.',
        ]);

        try {
            $expense = $this->expenses->record(
                category: ExpenseCategory::from($data['category']),
                amount: $data['amount'],
                method: ExpenseMethod::from($data['method']),
                paidAt: CarbonImmutable::parse($data['paid_at']),
                note: $data['note'],
                busId: $data['bus_id'] ?? null,
                reservationId: $data['reservation_id'] ?? null,
                supplierName: $data['supplier_name'] ?? null,
                reference: $data['reference'] ?? null,
                recordedBy: $request->user()?->id,
            );
        } catch (ExpenseException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Dépense enregistrée.',
            'data' => new ExpenseResource($expense->load('bus:id,plate,name')),
        ], 201);
    }

    /**
     * Cancels an expense with an opposing entry.
     *
     * `amount` omitted reverses whatever is left, which is the common case. A
     * partial is real: a supplier credits back one of three tyres.
     */
    public function reverse(Request $request, int $id)
    {
        $expense = Expense::findOrFail($id);

        $data = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ], [
            'note.required' => 'Indiquez pourquoi cette dépense est annulée.',
        ]);

        try {
            $reversal = $this->expenses->reverse(
                expense: $expense,
                note: $data['note'],
                amount: $data['amount'] ?? null,
                recordedBy: $request->user()?->id,
            );
        } catch (ExpenseException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Écriture inverse enregistrée.',
            'data' => new ExpenseResource($reversal),
        ], 201);
    }

    /**
     * Attaches or replaces the receipt.
     *
     * The old file is deleted only after the new path is committed, so a failed
     * write leaves the row pointing at a file that still exists rather than at
     * nothing.
     */
    public function receipt(Request $request, int $id)
    {
        $expense = Expense::findOrFail($id);

        $request->validate([
            // Images and PDFs. A receipt is photographed far more often than
            // it is scanned, so both have to work.
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ], [
            'file.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
        ]);

        $file = $request->file('file');
        $previous = $expense->receipt_path;

        $path = $file->store("expenses/{$expense->id}", 'public');

        $this->expenses->attachReceipt(
            $expense,
            $path,
            $file->getMimeType(),
            (int) ceil($file->getSize() / 1024),
        );

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json([
            'status' => true,
            'message' => 'Justificatif enregistré.',
            'data' => new ExpenseResource($expense->refresh()),
        ]);
    }

    /**
     * Net spend per vehicle, for the fleet screen.
     *
     * Separate from the fleet analytics endpoint on purpose: that one answers
     * revenue and is reached by a different screen with a different cache key.
     * Joining them would make a slow cost query hold up a tab that was only
     * asking what came in.
     */
    public function perBus(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])
            : CarbonImmutable::now()->startOfMonth();

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])
            : CarbonImmutable::now()->endOfMonth();

        return response()->json([
            'status' => true,
            'data' => $this->expenses->perBus($from, $to),
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }
}
