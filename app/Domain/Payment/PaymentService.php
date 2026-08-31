<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\Payable;
use App\Domain\Payment\DTOs\ChargeResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Settings\Facades\Settings;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Notifications\ManualPaymentRequested;
use App\Notifications\PaymentOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collecting money — for anything.
 *
 * The service owns the state machine; drivers only talk to providers. That
 * split is what keeps "when may a payment be marked paid" answerable by reading
 * one file rather than by auditing every integration.
 *
 * It collects for an Order, a PassSubscription or a Reservation without knowing
 * which: they all implement Payable, so there is no branch on type anywhere
 * below. That is the whole reason the payment surface is reusable rather than
 * duplicated per product.
 *
 * Four rules that matter more than the rest:
 *
 *  1. **The amount comes from the payable, never from the request.** A client
 *     posting `amount` would be naming their own price.
 *  2. **One live attempt at a time.** A mobile-money prompt sits on a handset
 *     for a minute or two; a client who taps "payer" again in that window must
 *     be shown the existing attempt, not debited a second time.
 *  3. **Terminal is terminal.** A late or duplicated webhook must never flip a
 *     refunded payment back to paid, and providers do re-deliver.
 *  4. **The success hook runs exactly once**, inside the transaction that
 *     records the success — so a subscription cannot be activated by a payment
 *     that then fails to save.
 */
class PaymentService
{
    public function __construct(private PaymentDriverRegistry $registry) {}

    /* ─────────────────────────── Starting ─────────────────────────── */

    /**
     * Starts a payment.
     *
     * @param  array<string, mixed>  $fields  Collected per the provider's `fields` descriptor.
     * @param  string  $kind  full | deposit | balance
     *
     * @throws PaymentException
     */
    public function start(
        Payable&Model $payable,
        ?Client $client,
        string $providerCode,
        array $fields = [],
        string $kind = 'full',
        string $channel = 'app',
        ?int $actorId = null,
    ): Payment {
        $provider = $this->registry->provider($providerCode);

        if (! $provider->enabled) {
            throw new PaymentException('Ce moyen de paiement n’est pas disponible actuellement.');
        }

        return DB::transaction(function () use (
            $payable, $client, $provider, $fields, $kind, $channel, $actorId
        ) {
            /*
             * Lock the payable for the duration, so two taps cannot both pass
             * the "is anything in flight?" check below. Locking the payment
             * rows instead would not help — the race is on their absence.
             */
            $payable = $payable->newQuery()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            if (! $payable->isPayable()) {
                throw new PaymentException(
                    'Cette commande ne peut pas encore être réglée. Notre équipe la valide et vous prévient.'
                );
            }

            $amount = $this->amountDue($payable, $kind);

            if ($amount <= 0) {
                throw new PaymentException(
                    'Cette commande n’a pas encore de montant. Notre équipe vous transmet la facture.'
                );
            }

            if (! $provider->accepts($amount, $payable->paymentCurrency())) {
                throw new PaymentException(
                    sprintf('%s n’accepte pas un montant de %s FCFA.', $provider->label, number_format($amount, 0, ',', ' '))
                );
            }

            $existing = $this->inFlightFor($payable);
            if ($existing) {
                // Not an error to the caller — the app shows the attempt that
                // is already running rather than opening a competing debit.
                return $existing;
            }

            $fee = $provider->feeOn($amount);
            $charged = $provider->totalFor($amount);

            $payment = Payment::create([
                'payable_type' => $payable::class,
                'payable_id' => $payable->getKey(),
                'client_id' => $client?->id ?? $payable->paymentClient()?->id,
                'provider_code' => $provider->code,
                'channel' => $channel,
                'kind' => $kind,
                'status' => PaymentStatus::Pending,
                // From the PAYABLE. See the class docblock.
                'amount' => $charged,
                'fee_amount' => $fee,
                'net_amount' => $charged - $fee,
                'currency' => $payable->paymentCurrency(),
                'payer_phone' => $fields['phone'] ?? null,
                // Generated BEFORE the provider is called, and passed to it as
                // its own request id where it accepts one. A retried HTTP
                // request therefore cannot become a second debit.
                'idempotency_key' => (string) Str::uuid(),
                /*
                 * How long this attempt stays answerable.
                 *
                 * Two very different clocks, because two very different things
                 * are being waited on. A mobile-money prompt sits on a handset
                 * and genuinely dies in minutes. A cash request is a job for a
                 * person, who may settle it tomorrow morning.
                 *
                 * Fifteen minutes was applied to both, which meant a cash
                 * request expired before anyone could act on it. It still has a
                 * ceiling rather than none: an in-flight attempt blocks any new
                 * one (see the guard above), so a request nobody ever actions
                 * must eventually lapse or the client can never choose another
                 * method.
                 */
                'expires_at' => CarbonImmutable::now()->addMinutes(
                    $this->registry->driverFor($provider)->capabilities()->statusPoll
                        ? config('payment.attempt_ttl_minutes', 15)
                        : config('payment.manual_attempt_ttl_minutes', 60 * 48),
                ),
                'meta' => ['fields' => $this->safeFields($fields)],
                'created_by' => $actorId,
            ]);

            $result = $this->callDriver(
                fn () => $this->registry->driverFor($provider)->charge($payment),
                $payment,
            );

            return $this->apply($payment, $result);
        });
    }

    /* ─────────────────────────── Recording ─────────────────────────── */

    /**
     * Records what a provider reported.
     *
     * The one place a payment changes state. Refuses to move a payment that has
     * already reached a terminal state — providers re-deliver webhooks, and a
     * replayed `succeeded` must never resurrect a refunded payment.
     */
    public function apply(Payment $payment, ChargeResult $result): Payment
    {
        /*
         * Already terminal. This early return is also what makes the
         * notification below fire exactly once: a webhook re-delivered three
         * times, or a webhook racing the reconciler, all land here and stop.
         */
        if ($payment->status->isFinal()) {
            return $payment;
        }

        $settled = DB::transaction(function () use ($payment, $result) {
            $now = CarbonImmutable::now();

            $payment->status = $result->status;
            $payment->provider_reference = $result->reference ?? $payment->provider_reference;
            $payment->meta = array_merge($payment->meta ?? [], $result->meta);

            match ($result->status) {
                PaymentStatus::Processing => $payment->processing_at = $now,
                PaymentStatus::Succeeded => $payment->paid_at = $now,
                PaymentStatus::Failed, PaymentStatus::Cancelled => tap($payment, function ($p) use ($now, $result) {
                    $p->failed_at = $now;
                    $p->failure_reason = $result->message;
                }),
                default => null,
            };

            $payment->save();

            if ($result->status === PaymentStatus::Succeeded) {
                $this->onSucceeded($payment);
            }

            return $payment->refresh();
        });

        /*
         * The client hears about it, AFTER the transaction commits.
         *
         * This is the gap that made every payment except a counter collection
         * silent: money settling through a webhook, through reconciliation or
         * through an admin confirming it by hand told nobody. The client
         * watched a sheet spin, closed it, and had no receipt of any kind.
         *
         * After the commit rather than inside it, for the reason the whole
         * codebase applies: a rollback would have told somebody their trip was
         * paid for when it was not.
         */
        $this->announce($settled, $result);

        return $settled;
    }

    /**
     * Tells the client where their money got to.
     *
     * Only for terminal states. `processing` is the app's own screen, not a
     * push, and a notification for it would fire on every poll.
     *
     * Wrapped, because a mail server being down must never turn a settled
     * payment into an exception. The money has arrived; failing here would
     * roll nothing back and would only lose the record of it.
     */
    private function announce(Payment $payment, ChargeResult $result): void
    {
        /*
         * A cash or transfer request needs a HUMAN, so tell one.
         *
         * This is the only case where `processing` is worth announcing, and it
         * is announced to staff rather than to the client. Mobile money
         * resolves itself through a webhook or the reconciler whether anybody
         * is watching or not; a manual payment sits here until a person
         * collects the money, and nothing used to say it had arrived. Ops had
         * to happen to be looking at the Paiements page.
         *
         * That silence is also what hid the expiry bug: the request was failing
         * itself after fifteen minutes, and with no announcement the only
         * evidence was its absence from a list nobody had reason to refresh.
         */
        if ($result->status === PaymentStatus::Processing) {
            $this->alertStaffToManualPayment($payment);

            return;
        }

        if (! in_array($result->status, [PaymentStatus::Succeeded, PaymentStatus::Failed], true)) {
            return;
        }

        try {
            $client = $payment->client ?? $payment->payable?->paymentClient();

            if (! $client) {
                // A back-office collection for a walk-in has no account to
                // notify. Not an error, just nobody to tell.
                return;
            }

            $client->notify(new PaymentOutcome(
                $payment,
                $result->status === PaymentStatus::Succeeded,
            ));
        } catch (Throwable $e) {
            Log::error('Payment settled but the client could not be notified', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tells staff that money is waiting to be collected by hand.
     *
     * Only for drivers that cannot settle themselves. A mobile-money prompt
     * also passes through `processing`, and alerting an agent every time
     * somebody taps MTN would make this notification worthless within a day.
     * The capability is the right test rather than a list of provider codes:
     * a new manual-style provider added from Settings gets this for free.
     *
     * Active admins and agents, matching how a new order is announced. No
     * fallback to a shared inbox: unlike a lead, an unnoticed payment request
     * costs nobody a sale, and mailing an address nobody owns is how alerts
     * start being ignored.
     *
     * Wrapped, because a mail server being down must never turn a recorded
     * payment into an exception.
     */
    private function alertStaffToManualPayment(Payment $payment): void
    {
        try {
            if ($this->registry->driver($payment->provider_code)->capabilities()->statusPoll) {
                return;
            }

            $staff = User::whereIn('role', User::STAFF_ROLES)
                ->where('status', 'active')
                ->get();

            if ($staff->isEmpty()) {
                return;
            }

            NotificationFacade::send($staff, new ManualPaymentRequested($payment));
        } catch (Throwable $e) {
            Log::error('Manual payment recorded but staff could not be alerted', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The success hook, run once, inside the transaction that recorded it.
     *
     * Wrapped: a payable whose own bookkeeping throws must not roll back the
     * fact that money arrived. Losing the payment record because a notification
     * failed is strictly worse than an order left in the wrong status, which a
     * human can fix.
     */
    private function onSucceeded(Payment $payment): void
    {
        try {
            $payment->payable?->onPaymentSucceeded($payment);
        } catch (Throwable $e) {
            Log::error('Payment succeeded but the payable hook failed', [
                'payment_id' => $payment->id,
                'payable' => $payment->payable_type . '#' . $payment->payable_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ─────────────────────────── Polling ─────────────────────────── */

    /**
     * Asks the provider where a payment stands.
     *
     * Used by `payments:reconcile` and by the app's own refresh. Webhooks get
     * lost; without this a client watches "en cours" forever and support has
     * no answer either.
     */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->status->isFinal()) {
            return $payment;
        }

        $driver = $this->registry->driver($payment->provider_code);

        /*
         * A driver with nothing to poll has nothing to expire.
         *
         * This used to call `expireIfStale`, which failed the attempt fifteen
         * minutes after it was created. For a mobile-money prompt that is
         * right: the prompt on the handset really does die. For cash it was
         * wrong and it broke the feature outright.
         *
         * A client tapping Especes creates a `processing` payment that an agent
         * settles by hand, possibly tomorrow. The app polls this endpoint while
         * the sheet is open, so a quarter of an hour later the request marked
         * itself failed and vanished from the back office's Paiements list,
         * which filters on `processing`. The client was told a request had been
         * sent and ops never saw one.
         *
         * `ReconcilePayments` already had this right, with a comment saying so:
         * "Manual and credit payments are skipped, not expired. Their status
         * only ever changes because a person changed it, so expiring them would
         * cancel work an agent is still doing." The cron protected these
         * payments and this method killed them. Now both agree.
         *
         * They are still not immortal: `start()` gives them a long window, so a
         * request nobody actions eventually lapses and the client can choose
         * another method rather than being locked out for good.
         */
        if (! $driver->capabilities()->statusPoll) {
            return $payment;
        }

        $result = $this->callDriver(fn () => $driver->status($payment), $payment);

        return $this->apply($this->expireIfStale($payment), $result);
    }

    /**
     * Fails an attempt whose prompt can no longer be answered.
     *
     * Better than leaving it pending forever: the client gets a clear failure
     * and a clean retry, instead of a payment that blocks new attempts (rule 2)
     * for the life of the record.
     */
    public function expireIfStale(Payment $payment): Payment
    {
        if ($payment->status->isFinal() || ! $payment->expires_at || $payment->expires_at->isFuture()) {
            return $payment;
        }

        return $this->apply($payment, new ChargeResult(
            PaymentStatus::Failed,
            $payment->provider_reference,
            'La demande de paiement a expiré. Vous pouvez réessayer.',
        ));
    }

    /* ─────────────────────────── Refunds ─────────────────────────── */

    /**
     * Sends money back, as a CHILD payment.
     *
     * The original is marked refunded but otherwise left alone, so the attempt
     * that actually collected the money stays readable. Mutating it into a
     * refund would destroy the record of the collection.
     *
     * @throws PaymentException
     */
    public function refund(Payment $payment, ?int $amount = null, ?int $actorId = null): Payment
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            throw new PaymentException('Seul un paiement abouti peut être remboursé.');
        }

        $amount ??= $payment->amount;

        if ($amount <= 0 || $amount > $payment->amount) {
            throw new PaymentException('Montant de remboursement invalide.');
        }

        $driver = $this->registry->driver($payment->provider_code);

        if (! $driver->capabilities()->refund) {
            throw new PaymentException(
                sprintf('%s ne permet pas le remboursement automatique. Traitez-le manuellement.', $payment->provider_code)
            );
        }

        return DB::transaction(function () use ($payment, $amount, $actorId, $driver) {
            $refund = Payment::create([
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'client_id' => $payment->client_id,
                'provider_code' => $payment->provider_code,
                'channel' => 'back_office',
                'kind' => 'refund',
                'parent_payment_id' => $payment->id,
                'status' => PaymentStatus::Pending,
                'amount' => $amount,
                'net_amount' => $amount,
                'currency' => $payment->currency,
                'payer_phone' => $payment->payer_phone,
                'idempotency_key' => (string) Str::uuid(),
                'created_by' => $actorId,
            ]);

            $result = $this->callDriver(fn () => $driver->refund($payment, $amount), $refund);

            $this->apply($refund, $result);

            if ($refund->refresh()->status === PaymentStatus::Succeeded) {
                // Only now. Marking the original refunded before the money
                // moved would show a client a refund that never happened.
                $payment->update(['status' => PaymentStatus::Refunded]);
            }

            return $refund;
        });
    }

    /* ─────────────────────────── Amounts ─────────────────────────── */

    /**
     * What a given attempt should collect.
     *
     * `deposit` reads its share from settings, so ops can move the down payment
     * from 30% to 50% without a deploy. `balance` is whatever is left, which is
     * the only figure that cannot be got wrong by arithmetic elsewhere.
     */
    public function amountDue(Payable $payable, string $kind = 'full'): int
    {
        $total = $payable->paymentAmount();
        $paid = $this->paidTotal($payable);

        return match ($kind) {
            'deposit' => max(0, min(
                (int) ceil($total * $this->depositShare()) - $paid,
                $total - $paid,
            )),
            'balance' => max(0, $total - $paid),
            default => max(0, $total - $paid),
        };
    }

    /** The configured down-payment share, clamped to something sane. */
    public function depositShare(): float
    {
        return max(0.05, min(1.0, Settings::float('rules.deposit_percent', 0.3)));
    }

    public function allowsDeposit(Payable $payable): bool
    {
        return Settings::bool('rules.allow_deposit', true)
            && $this->paidTotal($payable) === 0
            && $this->depositShare() < 1.0;
    }

    /** Everything successfully collected against this payable. */
    public function paidTotal(Payable&Model $payable): int
    {
        return (int) Payment::forPayable($payable)
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('kind', '!=', 'refund')
            ->sum('amount');
    }

    public function isPaid(Payable&Model $payable): bool
    {
        return $payable->paymentAmount() > 0
            && $this->paidTotal($payable) >= $payable->paymentAmount();
    }

    public function inFlightFor(Payable&Model $payable): ?Payment
    {
        return Payment::forPayable($payable)->inFlight()->latest('id')->first();
    }

    /* ─────────────────────────── Plumbing ─────────────────────────── */

    /**
     * Runs a driver call and turns any escape into a clean failure.
     *
     * A provider timing out, returning malformed JSON, or throwing must produce
     * a French message the client can act on — never a stack trace, and never a
     * raw provider code. The real error goes to the log and to Sentry, where it
     * is correlated by `request_id`.
     */
    private function callDriver(callable $call, Payment $payment): ChargeResult
    {
        try {
            return $call();
        } catch (Throwable $e) {
            Log::error('Payment driver failed', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider_code,
                'error' => $e->getMessage(),
            ]);

            report($e);

            return ChargeResult::failed(
                'Le service de paiement est momentanément indisponible. Réessayez dans un instant.',
                ['driver_error' => class_basename($e)],
            );
        }
    }

    /**
     * Strips anything that must not be persisted from the collected fields.
     *
     * A PIN or an OTP must never reach `meta`, which is kept for years for
     * reconciliation disputes. Providers should never ask us for one — but a
     * future adapter descriptor could, and this is where that stops.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function safeFields(array $fields): array
    {
        $forbidden = ['pin', 'otp', 'code', 'password', 'cvv', 'card_number'];

        return collect($fields)
            ->reject(fn ($v, $k) => Str::contains(Str::lower((string) $k), $forbidden))
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, PaymentProvider> */
    public function availableProviders(int $amount, string $currency = 'XAF', ?string $country = 'CG')
    {
        return $this->registry->available($amount, $currency, $country);
    }
}
