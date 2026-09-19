<?php

namespace App\Domain\Finance;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Enums\ExpenseMethod;
use App\Domain\Finance\Exceptions\ExpenseException;
use App\Domain\Settings\Facades\Settings;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one writer for the expense ledger.
 *
 * Controllers do not call Expense::create(). Everything funnels through here so
 * the closed-books check, the reversal arithmetic and the actor stamp exist in
 * exactly one place, which is what stops the ledger acquiring a row that no
 * rule was applied to.
 *
 * Reporting lives here too rather than in an analytics controller, because
 * summing an expense ledger means knowing that `amount` is unsigned and a
 * reversal subtracts. That is a property of this domain, and a caller that has
 * to remember it will eventually forget.
 */
class ExpenseService
{
    /**
     * The books-closed date.
     *
     * Nothing may be recorded on or before it. One setting rather than a
     * periods table, because the whole job today is "stop last month's total
     * changing after it has been reported on", and a date does that completely.
     * When a real period close is built, with its own totals snapshot and an
     * audit of who closed what, it supersedes this; until then a table of
     * periods would be scaffolding holding up one boolean.
     *
     * Empty by default: no books are closed until somebody says so.
     */
    private function closedBefore(): ?CarbonImmutable
    {
        $value = Settings::string('finance.books_closed_before', '');

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            /*
             * A malformed setting must not block every expense in the system.
             * Treated as "no books closed" and left to the settings screen to
             * fix, rather than throwing on a code path whose job is recording
             * a fuel receipt.
             */
            return null;
        }
    }

    /**
     * Records money going out.
     *
     * @param  int  $amount  Whole francs, positive.
     *
     * @throws ExpenseException
     */
    public function record(
        ExpenseCategory $category,
        int $amount,
        ExpenseMethod $method,
        CarbonImmutable $paidAt,
        string $note,
        ?int $busId = null,
        /*
         * A string, not an int: `reservations.id` is a uuid. Typed `?int` here
         * originally, which made attaching a cost to a trip throw a TypeError
         * on the one path that mattered. Part of issue #26.
         */
        ?string $reservationId = null,
        ?string $supplierName = null,
        ?string $reference = null,
        ?int $recordedBy = null,
    ): Expense {
        if ($amount <= 0) {
            throw new ExpenseException('Le montant doit être positif.');
        }

        /*
         * A future-dated expense is refused.
         *
         * On a cash basis `paid_at` asserts that money has already left. A date
         * next week is either a typo or an intention, and an intention recorded
         * as a fact is how a P&L starts showing costs that have not happened.
         */
        if ($paidAt->startOfDay()->isAfter(CarbonImmutable::now()->endOfDay())) {
            throw new ExpenseException('La date de paiement ne peut pas être dans le futur.');
        }

        $this->assertPeriodOpen($paidAt);

        return Expense::create([
            'category' => $category,
            'direction' => 'debit',
            'amount' => $amount,
            'currency' => 'XAF',
            'method' => $method,
            'paid_at' => $paidAt->toDateString(),
            'bus_id' => $busId,
            'reservation_id' => $reservationId,
            'supplier_name' => $supplierName,
            'reference' => $reference,
            'note' => $note,
            'recorded_by' => $recordedBy,
        ]);
    }

    /**
     * Cancels an expense, wholly or in part, with an opposing entry.
     *
     * The original is never touched. A partial reversal is a real case: a
     * supplier credits back one of three tyres, and the cost that remains is
     * the two that were kept.
     *
     * Runs under a lock on the original row, so two people reversing the same
     * expense at the same instant produce one reversal and one refusal rather
     * than two credits that together exceed the debit.
     *
     * @param  int|null  $amount  Omitted means the full remaining balance.
     *
     * @throws ExpenseException
     */
    public function reverse(
        Expense $expense,
        string $note,
        ?int $amount = null,
        ?int $recordedBy = null,
    ): Expense {
        if ($expense->isReversal()) {
            throw new ExpenseException('Une écriture inverse ne peut pas être annulée.');
        }

        return DB::transaction(function () use ($expense, $note, $amount, $recordedBy) {
            /** @var Expense $original */
            $original = Expense::whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            $alreadyReversed = (int) Expense::where('reverses_id', $original->id)
                ->where('direction', 'credit')
                ->sum('amount');

            $remaining = $original->amount - $alreadyReversed;

            if ($remaining <= 0) {
                throw new ExpenseException('Cette dépense a déjà été entièrement annulée.');
            }

            $value = $amount ?? $remaining;

            if ($value <= 0) {
                throw new ExpenseException('Le montant doit être positif.');
            }

            if ($value > $remaining) {
                throw new ExpenseException(sprintf(
                    'Le montant dépasse le reste à annuler (%s FCFA).',
                    number_format($remaining, 0, ',', ' ')
                ));
            }

            /*
             * The reversal is dated TODAY, not on the original's date.
             *
             * Cash basis: the money came back when it came back. Back-dating it
             * onto the original would silently restate a month that has already
             * been reported on, which is the exact thing the closed-books check
             * exists to prevent.
             */
            $today = CarbonImmutable::now();
            $this->assertPeriodOpen($today);

            return Expense::create([
                'category' => $original->category,
                'direction' => 'credit',
                'amount' => $value,
                'currency' => $original->currency,
                'method' => $original->method,
                'paid_at' => $today->toDateString(),
                'bus_id' => $original->bus_id,
                'reservation_id' => $original->reservation_id,
                'supplier_name' => $original->supplier_name,
                'reference' => $original->reference,
                'note' => $note,
                'reverses_id' => $original->id,
                'recorded_by' => $recordedBy,
            ]);
        });
    }

    /**
     * Attaches or replaces the receipt.
     *
     * The one mutation an expense permits; see Expense::MUTABLE for why it is
     * the only one. Kept here rather than in the controller so the allowlist
     * has a single caller.
     */
    public function attachReceipt(Expense $expense, string $path, ?string $mime, int $sizeKb): Expense
    {
        $expense->update([
            'receipt_path' => $path,
            'receipt_mime' => $mime,
            'receipt_size_kb' => $sizeKb,
        ]);

        return $expense->refresh();
    }

    /** @throws ExpenseException */
    private function assertPeriodOpen(CarbonImmutable $date): void
    {
        $closed = $this->closedBefore();

        if ($closed !== null && $date->startOfDay()->lessThanOrEqualTo($closed)) {
            throw new ExpenseException(sprintf(
                'Les comptes sont clôturés jusqu’au %s. Cette écriture ne peut pas y être ajoutée.',
                $closed->format('d/m/Y')
            ));
        }
    }

    /**
     * Net spend over a period, broken down for a P&L.
     *
     * Every figure is NET of reversals, computed in SQL as
     * `debits - credits` rather than by loading rows and summing
     * `signedAmount()`. A year of expenses is not a set anybody should hydrate
     * into Eloquent models to add up.
     *
     * Returned positive, because "we spent 4.2M on fuel" is how the number is
     * read and written everywhere it is displayed. The sign convention lives at
     * the row level, on `Expense::signedAmount()`.
     *
     * @return array{
     *     total:int,
     *     direct:int,
     *     overhead:int,
     *     by_category: array<int, array{category:string, label:string, nature:string, total:int, count:int}>,
     *     by_method: array<int, array{method:string, label:string, total:int, count:int}>
     * }
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $net = "SUM(CASE WHEN direction = 'credit' THEN -amount ELSE amount END)";

        $rows = Expense::query()
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->groupBy('category')
            ->selectRaw("category, {$net} as total, COUNT(*) as count")
            ->get();

        $byCategory = $rows
            ->map(function ($row) {
                $category = $row->category instanceof ExpenseCategory
                    ? $row->category
                    : ExpenseCategory::tryFrom((string) $row->category);

                return [
                    'category' => $category?->value ?? (string) $row->category,
                    'label' => $category?->label() ?? (string) $row->category,
                    'nature' => $category?->nature() ?? 'overhead',
                    'total' => (int) $row->total,
                    'count' => (int) $row->count,
                ];
            })
            /*
             * A category whose debits and credits cancel exactly contributes
             * nothing and is dropped, or the breakdown grows a permanent row of
             * zeroes for every mistake that was ever corrected.
             */
            ->filter(fn (array $row) => $row['total'] !== 0)
            ->sortByDesc('total')
            ->values()
            ->all();

        $methods = Expense::query()
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->groupBy('method')
            ->selectRaw("method, {$net} as total, COUNT(*) as count")
            ->get()
            ->map(function ($row) {
                $method = $row->method instanceof ExpenseMethod
                    ? $row->method
                    : ExpenseMethod::tryFrom((string) $row->method);

                return [
                    'method' => $method?->value ?? (string) $row->method,
                    'label' => $method?->label() ?? (string) $row->method,
                    'total' => (int) $row->total,
                    'count' => (int) $row->count,
                ];
            })
            ->filter(fn (array $row) => $row['total'] !== 0)
            ->sortByDesc('total')
            ->values()
            ->all();

        $direct = array_sum(array_column(
            array_filter($byCategory, fn ($r) => $r['nature'] === 'direct'), 'total'
        ));

        return [
            'total' => array_sum(array_column($byCategory, 'total')),
            'direct' => (int) $direct,
            'overhead' => array_sum(array_column($byCategory, 'total')) - (int) $direct,
            'by_category' => $byCategory,
            'by_method' => $methods,
        ];
    }

    /**
     * Net spend per vehicle, which is the figure the fleet dashboard lacked.
     *
     * Only expenses actually attributed to a bus appear. Unattributed cost is
     * deliberately NOT spread across the fleet: an apportioned figure looks
     * exactly like a measured one and is far less true, and the honest answer
     * to "what did this bus cost" is the total somebody took the trouble to
     * attribute to it.
     *
     * @return array<int, array{bus_id:int, total:int, count:int}>
     */
    public function perBus(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $net = "SUM(CASE WHEN direction = 'credit' THEN -amount ELSE amount END)";

        return Expense::query()
            ->whereNotNull('bus_id')
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->groupBy('bus_id')
            ->selectRaw("bus_id, {$net} as total, COUNT(*) as count")
            ->get()
            ->map(fn ($row) => [
                'bus_id' => (int) $row->bus_id,
                'total' => (int) $row->total,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /** Shared filter surface for the list endpoint. */
    public function filtered(array $filters): Builder
    {
        return Expense::query()
            ->with(['bus:id,plate,name', 'recorder:id,name'])
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('method', $v))
            ->when($filters['bus_id'] ?? null, fn ($q, $v) => $q->where('bus_id', $v))
            ->when($filters['reservation_id'] ?? null, fn ($q, $v) => $q->where('reservation_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $term = '%' . $v . '%';
                $q->where(fn ($w) => $w
                    ->where('supplier_name', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('note', 'like', $term));
            })
            /*
             * By the date money moved, then by id.
             *
             * The tiebreak matters: paid_at is a DATE, so a day's entries are
             * otherwise in whatever order the engine feels like, and a list that
             * reshuffles between two identical requests looks broken and makes
             * pagination drop rows.
             */
            ->orderByDesc('paid_at')
            ->orderByDesc('id');
    }
}
