<?php

namespace Tests\Feature;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Enums\ExpenseMethod;
use App\Domain\Finance\ExpenseService;
use App\Domain\Settings\Facades\Settings;
use App\Models\Expense;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The expense ledger.
 *
 * The tests that matter here are the refusals and the arithmetic. An expense
 * ledger earns its keep by being impossible to quietly rewrite, so what is
 * asserted is mostly that things CANNOT be done: an agent cannot see the
 * company's costs, a recorded expense cannot be edited or deleted, a reversal
 * cannot exceed what is left, and a closed month cannot gain a new row.
 *
 * The other half is that totals are net of reversals. A ledger that double
 * counts a corrected mistake is worse than the spreadsheet it replaced, because
 * it is trusted.
 */
class ExpenseLedgerTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function staff(string $role = 'admin'): User
    {
        $this->seq++;

        return User::create([
            'name' => 'Ops',
            'email' => 'u'.uniqid().'@example.test',
            'phone' => '+2420600'.str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'password' => bcrypt('secret'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function service(): ExpenseService
    {
        return app(ExpenseService::class);
    }

    private function record(int $amount, ?string $paidAt = null, ?ExpenseCategory $category = null): Expense
    {
        return $this->service()->record(
            category: $category ?? ExpenseCategory::Fuel,
            amount: $amount,
            method: ExpenseMethod::Cash,
            paidAt: CarbonImmutable::parse($paidAt ?? 'today'),
            note: 'Plein Hiace',
        );
    }

    /* Access */

    public function test_an_agent_cannot_see_company_expenses(): void
    {
        $this->actingAsBackOffice($this->staff('agent'));

        $this->getJson('/api/admin/expenses')->assertForbidden();
        $this->postJson('/api/admin/expenses', [])->assertForbidden();
    }

    public function test_an_admin_can_list_expenses(): void
    {
        $this->actingAsBackOffice($this->staff('admin'));

        $this->record(15_000);

        $this->getJson('/api/admin/expenses')
            ->assertOk()
            ->assertJsonPath('data.0.amount', 15_000)
            ->assertJsonPath('data.0.category', 'carburant')
            ->assertJsonPath('data.0.nature', 'direct');
    }

    /* Immutability */

    public function test_a_recorded_expense_cannot_be_edited(): void
    {
        $expense = $this->record(15_000);

        $this->expectException(\LogicException::class);

        $expense->update(['amount' => 1]);
    }

    public function test_a_recorded_expense_cannot_be_deleted(): void
    {
        $expense = $this->record(15_000);

        $this->expectException(\LogicException::class);

        $expense->delete();
    }

    public function test_attaching_a_receipt_is_permitted(): void
    {
        Storage::fake('public');
        $this->actingAsBackOffice($this->staff('admin'));

        $expense = $this->record(15_000);

        $this->postJson("/api/admin/expenses/{$expense->id}/receipt", [
            'file' => UploadedFile::fake()->image('recu.jpg'),
        ])->assertOk();

        $this->assertNotNull($expense->refresh()->receipt_path);
    }

    /* Reversals */

    public function test_a_reversal_is_a_new_row_and_leaves_the_original_alone(): void
    {
        $expense = $this->record(15_000);

        $reversal = $this->service()->reverse($expense, 'Remboursé par le garage');

        $this->assertSame('credit', $reversal->direction);
        $this->assertSame(15_000, $reversal->amount);
        $this->assertSame($expense->id, $reversal->reverses_id);

        // The original is untouched, which is the whole point.
        $this->assertSame(15_000, $expense->refresh()->amount);
        $this->assertSame('debit', $expense->direction);
    }

    public function test_a_partial_reversal_leaves_the_remainder_reversible(): void
    {
        $expense = $this->record(15_000);

        $this->service()->reverse($expense, 'Un pneu repris', 5_000);
        $second = $this->service()->reverse($expense, 'Le reste repris');

        // The second reversal takes exactly what was left, not the full amount.
        $this->assertSame(10_000, $second->amount);
    }

    public function test_a_reversal_cannot_exceed_what_is_left(): void
    {
        $expense = $this->record(15_000);

        $this->service()->reverse($expense, 'Partiel', 12_000);

        $this->expectExceptionMessage('dépasse le reste à annuler');

        $this->service()->reverse($expense, 'Trop', 5_000);
    }

    public function test_an_expense_cannot_be_reversed_twice_over(): void
    {
        $expense = $this->record(15_000);

        $this->service()->reverse($expense, 'Annulation totale');

        $this->expectExceptionMessage('déjà été entièrement annulée');

        $this->service()->reverse($expense, 'Encore');
    }

    /* Dates */

    public function test_a_future_dated_expense_is_refused(): void
    {
        $this->expectExceptionMessage('ne peut pas être dans le futur');

        $this->record(15_000, CarbonImmutable::now()->addDay()->toDateString());
    }

    public function test_a_closed_period_refuses_new_entries(): void
    {
        Settings::set('finance', 'books_closed_before', CarbonImmutable::now()->subDays(5)->toDateString());

        // Inside the closed window.
        try {
            $this->record(15_000, CarbonImmutable::now()->subDays(10)->toDateString());
            $this->fail('An expense inside a closed period should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('clôturés', $e->getMessage());
        }

        // After it, still fine.
        $this->assertNotNull($this->record(15_000, CarbonImmutable::now()->subDay()->toDateString()));
    }

    /* Arithmetic */

    public function test_the_summary_is_net_of_reversals(): void
    {
        $fuel = $this->record(20_000, 'today', ExpenseCategory::Fuel);
        $this->record(5_000, 'today', ExpenseCategory::Rent);

        $this->service()->reverse($fuel, 'Erreur de saisie', 8_000);

        $summary = $this->service()->summary(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        );

        // 20 000 fuel less an 8 000 reversal, plus 5 000 rent.
        $this->assertSame(17_000, $summary['total']);
        $this->assertSame(12_000, $summary['direct']);
        $this->assertSame(5_000, $summary['overhead']);

        $fuelRow = collect($summary['by_category'])->firstWhere('category', 'carburant');
        $this->assertSame(12_000, $fuelRow['total']);
    }

    public function test_a_fully_reversed_category_drops_out_of_the_breakdown(): void
    {
        $expense = $this->record(20_000, 'today', ExpenseCategory::Fuel);
        $this->service()->reverse($expense, 'Annulée');

        $summary = $this->service()->summary(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        );

        $this->assertSame(0, $summary['total']);
        $this->assertSame([], $summary['by_category']);
    }

    public function test_the_summary_only_counts_the_window_asked_for(): void
    {
        $this->record(20_000, CarbonImmutable::now()->startOfMonth()->toDateString());
        $this->record(9_000, CarbonImmutable::now()->subMonths(2)->toDateString());

        $summary = $this->service()->summary(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        );

        $this->assertSame(20_000, $summary['total']);
    }

    /* Validation at the edge */

    public function test_an_expense_requires_a_note(): void
    {
        $this->actingAsBackOffice($this->staff('admin'));

        $this->postJson('/api/admin/expenses', [
            'category' => 'carburant',
            'amount' => 10_000,
            'method' => 'especes',
            'paid_at' => CarbonImmutable::now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_the_api_refuses_a_future_date_with_a_field_error(): void
    {
        $this->actingAsBackOffice($this->staff('admin'));

        $this->postJson('/api/admin/expenses', [
            'category' => 'carburant',
            'amount' => 10_000,
            'method' => 'especes',
            'paid_at' => CarbonImmutable::now()->addWeek()->toDateString(),
            'note' => 'Plein',
        ])->assertStatus(422)->assertJsonValidationErrors('paid_at');
    }

    /* Schema, see issue #26 */

    /**
     * The regression guard for the foreign key that broke the first deploy.
     *
     * `reservations.id` is a uuid, and the original migration declared
     * `reservation_id` with `foreignId()`, which is an unsigned bigint. MySQL
     * rejects a foreign key whose types do not match, with errno 150.
     *
     * This assertion works on sqlite even though sqlite does not enforce the
     * constraint itself, because the two declarations still produce different
     * COLUMN TYPES: `foreignId` gives an integer, `uuid` gives a varchar.
     * Comparing them is therefore a real guard here rather than something that
     * only fails on the production driver.
     */
    public function test_the_reservation_foreign_key_matches_the_key_it_references(): void
    {
        $this->assertSame(
            Schema::getColumnType('reservations', 'id'),
            Schema::getColumnType('expenses', 'reservation_id'),
        );
    }

    public function test_an_expense_can_be_attached_to_a_reservation(): void
    {
        $reservation = Reservation::create([
            'trip_date' => CarbonImmutable::now()->addDays(2),
            'from_location' => 'Brazzaville',
            'to_location' => 'Pointe-Noire',
            'passenger_name' => 'Client',
            'passenger_phone' => '+242060000000',
            'price_total' => 500_000,
            'status' => 'confirmed',
            'seats' => 0,
        ]);

        $expense = $this->service()->record(
            category: ExpenseCategory::Fuel,
            amount: 25_000,
            method: ExpenseMethod::Cash,
            paidAt: CarbonImmutable::parse('today'),
            note: 'Plein pour la mission',
            reservationId: $reservation->id,
        );

        // A uuid survives the round trip, which an integer column would have
        // silently truncated to 0 long before MySQL ever complained.
        $this->assertSame($reservation->id, $expense->refresh()->reservation_id);
    }
}
