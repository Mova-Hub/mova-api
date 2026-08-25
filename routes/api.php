<?php

use App\Http\Controllers\Api\ClientNotificationController;
use App\Http\Controllers\Api\ClientOrderController;
use App\Http\Controllers\Api\FcmController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SavedAddressController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\V2\Admin\ActivityLogController;
use App\Http\Controllers\Api\V2\Admin\AdminPaymentController;
use App\Http\Controllers\Api\V2\Admin\AnalyticsController;
use App\Http\Controllers\Api\V2\Admin\PassCardController as AdminPassCardController;
use App\Http\Controllers\Api\V2\Admin\PassPlanController as AdminPassPlanController;
use App\Http\Controllers\Api\V2\Admin\PassSubscriptionController as AdminPassSubscriptionController;
use App\Http\Controllers\Api\V2\Admin\PaymentProviderController;
use App\Http\Controllers\Api\V2\Admin\PricingSimulatorController;
use App\Http\Controllers\Api\V2\Admin\SettingsController;
use App\Http\Controllers\Api\V2\Admin\WalletAdminController;
use App\Http\Controllers\Api\V2\Pass\CardController as PassCardController;
use App\Http\Controllers\Api\V2\Payment\InvoiceController;
use App\Http\Controllers\Api\V2\Payment\PaymentController;
use App\Http\Controllers\Api\V2\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V2\Payment\WalletController;
use App\Http\Controllers\Api\V2\Pass\ControlController as PassControlController;
use App\Http\Controllers\Api\V2\Pass\PlanController as PassPlanController;
use App\Http\Controllers\Api\V2\Pass\SubscriptionController as PassSubscriptionController;
use App\Http\Controllers\Api\V2\QuoteController as MobileQuoteController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusController;
use App\Http\Controllers\BusDocumentController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmploiController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderRequestController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\StaffController;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::prefix('app/v1')->group(function () {

    // Guest Routes
    Route::post('/register', [ClientAuthController::class, 'register']);
    Route::post('/login', [ClientAuthController::class, 'login']);
    Route::post('/forgot-password', [ClientAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [ClientAuthController::class, 'resetPassword']);

    // Social sign-in (Google / Apple). Rate-limited: the endpoint performs an
    // outbound verification call per request, so it must not be a free amplifier.
    Route::post('/auth/social', [SocialAuthController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::post('/auth/social/nonce', [SocialAuthController::class, 'nonce'])
        ->middleware('throttle:20,1');

    // Protected Routes
    // Note: Sanctum automatically determines if the token belongs to a Client or User
    // But we add 'abilities' or middleware checks if we want to be strict.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [ClientAuthController::class, 'me']);
        Route::post('/update-profile', [ClientAuthController::class, 'updateProfile']);
        Route::post('/update-password', [ClientAuthController::class, 'updatePassword']);
        Route::post('/two-factor', [ClientAuthController::class, 'toggle2FA']);

        // Phone update / completion flow.
        //
        // Throttled because each request sends a real SMS: unthrottled, this is
        // a way to burn Twilio credit and spam an arbitrary number. 5/min is
        // ample for a legitimate retry.
        Route::post('/request-phone-update', [ClientAuthController::class, 'requestPhoneUpdate'])
            ->middleware('throttle:5,1');
        Route::post('/verify-phone-update', [ClientAuthController::class, 'verifyPhoneUpdate'])
            ->middleware('throttle:10,1');

        Route::post('/logout', [ClientAuthController::class, 'logout']);
        // Route::post('/fcm/token', [FcmController::class, 'store']);
        Route::post('/fcm/token', [ClientAuthController::class, 'updateFcmToken']);

        // Delete Account
        Route::delete('/account', [ClientAuthController::class, 'deleteAccount']);

        Route::get('/test-notification/{id}', function ($id) {
            $order = Order::find($id);
            if (!$order) return 'Order not found';

            // Force a status change for testing
            $order->status = 'converted';
            $order->save();

            // Trigger the notification manually
            $order->client->notify(new \App\Notifications\OrderStatusUpdated(
                $order,
                "TEST: Votre commande #{$order->id} a été validée !"
            ));

            return 'Notification Sent to ' . $order->client->name;
        });

        // Trip pricing for the app.
        //
        // A SECOND controller, not a change to the back-office /quote — that
        // one returns commission and operator payout, which must never reach a
        // customer's phone. Both share App\Domain\Pricing\PricingEngine.
        //
        // Throttled: each miss can trigger a billed Directions call.
        Route::post('/quote', MobileQuoteController::class)
            ->middleware('throttle:30,1');

        // Client sends the order from app
        Route::post('/orders/request', [OrderRequestController::class, 'store']);
        // History
        Route::get('/orders/history', [ClientOrderController::class, 'history']);
        Route::get('/orders/{id}', [ClientOrderController::class, 'show'])->whereNumber('id');

        /*
         * ── Paying ────────────────────────────────────────────────────────
         *
         * Generic over payables: `{type}` is `order` or `subscription`, so one
         * set of routes collects for a charter booking AND a Mova Pass. The
         * type is resolved against an allow-list in the controller — never to
         * a caller-supplied class name.
         *
         * Every handler scopes to $request->user(); an id in the URL is a
         * claim, not an authorisation. The AMOUNT is never accepted from the
         * request — see App\Domain\Payment\PaymentService.
         */
        Route::get('/payment/methods', [PaymentController::class, 'methods']);
        Route::get('/payment/{type}/{id}/options', [PaymentController::class, 'options']);
        Route::get('/payment/{type}/{id}/history', [PaymentController::class, 'index']);
        // Throttled: each attempt pushes a prompt to a real handset, so an
        // unbounded endpoint is a way to spam someone's phone.
        Route::post('/payment/{type}/{id}', [PaymentController::class, 'store'])
            ->middleware('throttle:10,1');
        // Polled while the prompt sits on the handset, so a looser limit than
        // the one above — reading a status costs nobody anything.
        Route::get('/payments/{uuid}', [PaymentController::class, 'show'])
            ->middleware('throttle:120,1');

        // ── Mova Credit ───────────────────────────────────────────────────
        //
        // READ ONLY. There is no top-up route and no cash-out route, and their
        // absence is the compliance posture, not an omission — see
        // MOVA-WALLET-AND-PAYMENTS.md §3.3. Credit is spent through the normal
        // payment routes above, with provider `mova_credit`.
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/entries', [WalletController::class, 'entries']);

        // Mints a short-lived SIGNED url the system browser can open — a
        // browser carries no bearer token. The document itself is public
        // route + signature, never a guessable id.
        Route::get('/orders/{id}/invoice-link', [InvoiceController::class, 'link'])
            ->whereNumber('id');

        // Saved addresses (Domicile / Travail / École + custom).
        // Always scoped to the authenticated client inside the controller.
        Route::get('/addresses', [SavedAddressController::class, 'index']);
        Route::post('/addresses', [SavedAddressController::class, 'store']);
        Route::put('/addresses/{id}', [SavedAddressController::class, 'update']);
        Route::delete('/addresses/{id}', [SavedAddressController::class, 'destroy']);

        /*
         * ── Mova Pass ─────────────────────────────────────────────────────
         *
         * Controllers live in Api\V2\Pass; the URL keeps the /app/v1 prefix
         * because that is the MOBILE API surface, and moving it would break
         * every released build for an internal namespace change. V2 is a code
         * generation, not a wire version.
         *
         * Every handler scopes to $request->user(). None of them accepts an id
         * it then trusts.
         */
        Route::prefix('pass')->group(function () {
            Route::get('/plans', PassPlanController::class);

            // One round trip for the whole tab: subscription + card + the flag
            // the UI gates on. Three sequential calls on a Brazzaville mobile
            // connection is a screen that assembles itself in front of you.
            Route::get('/me', [PassSubscriptionController::class, 'current']);

            Route::get('/subscriptions', [PassSubscriptionController::class, 'index']);
            Route::post('/subscriptions', [PassSubscriptionController::class, 'store']);
            Route::get('/subscriptions/{id}', [PassSubscriptionController::class, 'show'])
                ->whereNumber('id');
            Route::post('/subscriptions/{id}/cancel', [PassSubscriptionController::class, 'cancel'])
                ->whereNumber('id');

            // Auto-renewal is the ONE field a subscriber may change on their
            // own subscription, so it gets its own route rather than a generic
            // update that would put price and dates within reach of a request.
            Route::patch('/subscriptions/{id}', [PassSubscriptionController::class, 'updateAutoRenew'])
                ->whereNumber('id');

            Route::get('/cards', [PassCardController::class, 'index']);

            // Throttled hard: the printed serial is an activation credential,
            // so an unbounded endpoint here is a way to hunt for cards to claim.
            Route::post('/cards/activate', [PassCardController::class, 'activate'])
                ->middleware('throttle:5,1');

            // Self-service blacklisting (PC-1). Every hour between losing a
            // card and reporting it is free rides on the owner's subscription,
            // so this must not require a phone call or an open guichet.
            Route::post('/cards/{id}/block', [PassCardController::class, 'block'])
                ->whereNumber('id');

            // The subscriber reading their own card. Logged with source `app`,
            // which keeps it out of the boarding figures.
            Route::post('/scans', [PassCardController::class, 'scan'])
                ->middleware('throttle:30,1');
            Route::get('/scans', [PassCardController::class, 'history']);
        });

        // Notifications
        Route::get('/notifications', [ClientNotificationController::class, 'index']);
        Route::post('/notifications/read', [ClientNotificationController::class, 'markAsRead']);
    });
});


// Internal Routes

Route::prefix('auth')->group(function () {
    /*
     * `POST /auth/register` is GONE.
     *
     * It was public and unauthenticated, and it created a `User` — a staff
     * account — from nothing but a name, an email and a password. It happened
     * to fail at the database (users.role is NOT NULL with no default, and
     * register() never set one), so it 500'd rather than succeeding; that is
     * luck, not a control.
     *
     * Staff accounts are created by an admin through `POST /staff`, which
     * assigns a role and a status deliberately.
     */
    Route::post('login',    [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout',           [AuthController::class, 'logout']);
        Route::get('me',                [AuthController::class, 'me']);
        Route::put('me',                [AuthController::class, 'updateMe']);
        Route::post('change-password',  [AuthController::class, 'changePassword']);
        Route::put('toggle-2fa',        [AuthController::class, 'toggleTwoFA']);
        Route::post('verify-password',  [AuthController::class, 'verifyPassword']);
    });
});


// ---------------------------------------------------------
// Public Routes
// ---------------------------------------------------------
// 1. Les candidats peuvent voir les offres ouvertes
Route::get('/jobs/public', [EmploiController::class, 'publicIndex']);

// 2. Les candidats postulent et uploadent leur CV
Route::post('/candidates', [CandidateController::class, 'store']);

/*
 * Invoice PDF.
 *
 * OUTSIDE the Sanctum group on purpose: the download is opened by the system
 * browser, which carries no bearer token. `signed` is what authorises it — the
 * app calls the authenticated `/orders/{id}/invoice-link` to mint a URL valid
 * for thirty minutes, and without a valid signature this route refuses.
 *
 * An unsigned public route keyed on the order id would let anyone read every
 * client's itinerary, phone number and price by counting upwards.
 */
Route::get('/app/v1/invoices/{order}', [InvoiceController::class, 'download'])
    ->middleware('signed')
    ->whereNumber('order')
    ->name('invoice.download');

/*
 * Provider callbacks.
 *
 * The ONLY unauthenticated route that can move money, so it is worth reading
 * PaymentWebhookController before touching it. Three defences, because one of
 * the two providers offers no signature on collections:
 *
 *   1. Per-driver signature verification, refusing by default.
 *   2. The body is treated as a HINT — where the driver can poll, the provider
 *      is asked directly rather than believed.
 *   3. PaymentService::apply() refuses to move a terminal payment, so even an
 *      accepted forgery cannot flip a refunded payment back to paid.
 *
 * Throttled per provider rather than left open: a retry storm from a
 * misconfigured callback URL should not take the API down with it. Generous,
 * because a legitimate burst after an outage is exactly when we most want the
 * callbacks to land.
 */
Route::post('/webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:300,1')
    ->where('provider', '[a-z0-9_]+')
    ->name('payments.webhook');

Route::prefix('locations')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/autocomplete', [LocationController::class, 'autocomplete']);
    Route::get('/details/{placeId}', [LocationController::class, 'details']);
    Route::get('/reverse-geocode', [LocationController::class, 'reverseGeocode']);
    // Road-following itinerary. POST because the waypoint list is structured.
    Route::post('/directions', [LocationController::class, 'directions']);
});

/*
 * ══════════════════════════════════════════════════════════════════════════
 *  BACK-OFFICE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `auth:sanctum` ALONE IS NOT ENOUGH HERE, and this group ran on it for a long
 * time. `App\Models\Client` also uses `HasApiTokens`, and Sanctum resolves
 * whichever model owns the presented token — so every customer's mobile token
 * authenticated successfully against all of it: the full client list with names
 * and phone numbers, every reservation, the staff directory, and the pricing
 * engine's commission and operator payout.
 *
 * `staff` establishes "an active admin or agent". `admin` narrows further, on
 * the sections console/ has always guarded that way (staff, people, jobs,
 * dashboard). Never remove `staff` from this group.
 */
Route::middleware(['auth:sanctum', 'staff'])->group(function () {

    /*
     * Admin-only sections.
     *
     * Nested rather than repeated per route: a route added inside this block
     * inherits the guard, whereas a per-route `->middleware('admin')` is
     * something the next person has to remember.
     */
    Route::middleware('admin')->group(function () {
        // Staff management
        Route::post('/staff/bulk-status', [StaffController::class, 'bulkStatus']);
        Route::post('/staff/role',        [StaffController::class, 'setRole']); // promote/demote
        Route::apiResource('staff', StaffController::class);

        // People management (drivers / conductors / owners)
        Route::post('/person/bulk-status',        [PersonController::class, 'bulkStatus']);
        Route::post('/person/role',               [PersonController::class, 'setRole']);
        Route::post('/person/{person}/avatar',    [PersonController::class, 'uploadAvatar']);
        Route::apiResource('person', PersonController::class);
    });

    // Bus management
    Route::post('/buses/bulk-status',           [BusController::class, 'bulkStatus']);
    Route::post('/buses/bulk-destroy',          [BusController::class, 'bulkDestroy']);
    Route::apiResource('buses', BusController::class);

    // Bus actions
    Route::get('/buses/{bus}/stats',            [BusController::class, 'stats']);
    Route::post('/buses/{bus}/status',          [BusController::class, 'setStatus']);
    Route::post('/buses/{bus}/assign-driver',   [BusController::class, 'assignDriver']);
    Route::post('/buses/{bus}/assign-conductor',[BusController::class, 'assignConductor']);
    Route::post('/buses/{bus}/set-operator',    [BusController::class, 'setOperator']);

    // Bus documents
    Route::get('/buses/{bus}/documents',                        [BusDocumentController::class, 'index']);
    Route::post('/buses/{bus}/documents',                       [BusDocumentController::class, 'store']);
    Route::delete('/buses/{bus}/documents/{document}',          [BusDocumentController::class, 'destroy']);

    // Client Management
    Route::get('/clients', [ClientController::class, 'index']);

    /*
     * Bulk suspension — NOT bulk deletion.
     *
     * There is no bulk delete route for clients and there should not be: an
     * account carries orders, payments and invoices that must survive for
     * accounting, so suspension is the only mass action that makes sense.
     *
     * Declared BEFORE `/clients/{id}` or `bulk-block` gets matched as an id —
     * and `whereNumber` would then 404 it with no clue why.
     */
    Route::post('/clients/bulk-block', [ClientController::class, 'bulkBlock']);
    Route::post('/clients/bulk-unblock', [ClientController::class, 'bulkUnblock']);

    Route::get('/clients/{id}', [ClientController::class, 'show'])
        ->whereNumber('id')
        // Reading a customer's phone, email and history is a sensitive read.
        ->middleware('audit.read:client');
    // console/ has always called PUT /clients/{id}; it did not exist until now.
    Route::put('/clients/{id}', [ClientController::class, 'update'])->whereNumber('id');
    // Suspending an account revokes its tokens — see ClientController::block.
    Route::post('/clients/{id}/block', [ClientController::class, 'block'])->whereNumber('id');
    Route::post('/clients/{id}/unblock', [ClientController::class, 'unblock'])->whereNumber('id');

    // Order/Lead Management
    Route::get('/orders', [OrderController::class, 'index']);
    // Before `/orders/{id}`, same reason as the client bulk routes above.
    Route::post('/orders/bulk-status', [OrderController::class, 'bulkStatus']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::patch('/orders/{id}', [OrderController::class, 'update'])->whereNumber('id');

    // The Conversion Action
    Route::post('/orders/{id}/convert', [OrderController::class, 'convertToReservation']);

    // Reservations management
    Route::apiResource('/reservations', ReservationController::class);

    // soft-delete restore
    Route::post  ('/reservations/{reservation}/restore', [ReservationController::class, 'restore']);

    // actions
    Route::post('/reservations/{reservation}/status',      [ReservationController::class, 'setStatus']);
    Route::post('/reservations/{reservation}/sync-buses',  [ReservationController::class, 'syncBuses']);
    Route::post('/reservations/{reservation}/attach-bus',  [ReservationController::class, 'attachBus']);
    Route::post('/reservations/{reservation}/detach-bus',  [ReservationController::class, 'detachBus']);
    Route::post('/reservations/bulk-status',               [ReservationController::class, 'bulkStatus']);
    Route::post('/reservations/{reservation}/payment',      [ReservationController::class, 'payment']);

    // Quote endpoint(Pricing engine)
    Route::post('/quote', QuoteController::class);

    /*
     * ── Mova Pass: staff & Mova Control ───────────────────────────────────
     *
     * Sync surface for the inspector app, plus the counter's encoding flow.
     * Staff-guarded (`auth:sanctum` on the User model) — these expose the whole
     * fleet, unlike the /app/v1/pass routes which only ever see one client.
     *
     * Note what is NOT here: any route that returns a private key. The counter
     * asks for a signed payload; it never signs anything itself, and the secret
     * half of the key pair never leaves this server (PRD §4.1).
     */
    /*
     * ── Mova Pass administration ──────────────────────────────────────────
     *
     * The counter and back-office surface. Thin controllers over CardService,
     * SubscriptionService and PaymentService — no Pass logic lives in HTTP, so
     * the app and the back-office cannot drift apart on what "blocked" or
     * "renewed" means.
     */
    Route::prefix('admin/pass')->group(function () {
        Route::get('/plans', [AdminPassPlanController::class, 'index']);
        Route::post('/plans', [AdminPassPlanController::class, 'store']);
        Route::put('/plans/{id}', [AdminPassPlanController::class, 'update'])->whereNumber('id');
        Route::delete('/plans/{id}', [AdminPassPlanController::class, 'destroy'])->whereNumber('id');

        Route::get('/cards', [AdminPassCardController::class, 'index']);
        // Before /cards/{id}, or the literal segment is swallowed by the wildcard.
        Route::post('/cards/issue', [AdminPassCardController::class, 'issue']);
        Route::get('/cards/{id}', [AdminPassCardController::class, 'show'])->whereNumber('id');
        Route::post('/cards/{id}/replace', [AdminPassCardController::class, 'replace'])->whereNumber('id');
        Route::post('/cards/{id}/block', [AdminPassCardController::class, 'block'])->whereNumber('id');
        Route::post('/cards/{id}/reencode', [AdminPassCardController::class, 'reencode'])->whereNumber('id');

        Route::get('/subscriptions', [AdminPassSubscriptionController::class, 'index']);
        Route::post('/subscriptions', [AdminPassSubscriptionController::class, 'store']);
        Route::get('/subscriptions/{id}', [AdminPassSubscriptionController::class, 'show'])->whereNumber('id');
        Route::post('/subscriptions/{id}/activate', [AdminPassSubscriptionController::class, 'activate'])->whereNumber('id');
        Route::post('/subscriptions/{id}/cancel', [AdminPassSubscriptionController::class, 'cancel'])->whereNumber('id');
    });

    /*
     * ── Audit trail ───────────────────────────────────────────────────────
     *
     * Read-only, and admin-only: the log records what agents did, so agents are
     * not the audience for it. There is deliberately NO write or delete route —
     * an audit log an operator can edit proves nothing. Rows leave only by
     * ageing out through `activity:prune`.
     */
    Route::middleware('admin')->prefix('admin/activity')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index']);
        // Before /{id}, or these literals are swallowed by the wildcard.
        Route::get('/actions', [ActivityLogController::class, 'actions']);
        Route::get('/request/{requestId}', [ActivityLogController::class, 'byRequest']);
        Route::get('/{id}', [ActivityLogController::class, 'show'])->whereNumber('id');
        // Parsed device + approximate network origin. Separate from show() so
        // a slow lookup cannot hold up the audit record itself.
        Route::get('/{id}/fingerprint', [ActivityLogController::class, 'fingerprint'])->whereNumber('id');
    });

    /*
     * ── Payments ──────────────────────────────────────────────────────────
     *
     * Settling by hand, which is the other half of ManualPaymentDriver until a
     * provider contract exists (PRD D3) — and stays useful afterwards for cash
     * and bank transfers no provider will cover.
     */
    Route::prefix('admin/payments')->group(function () {
        Route::get('/', [AdminPaymentController::class, 'index']);
        Route::get('/{id}', [AdminPaymentController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/confirm', [AdminPaymentController::class, 'confirm'])->whereNumber('id');
        Route::post('/{id}/fail', [AdminPaymentController::class, 'fail'])->whereNumber('id');
        Route::post('/{id}/refund', [AdminPaymentController::class, 'refund'])->whereNumber('id');
    });

    /*
     * ── Settings ──────────────────────────────────────────────────────────
     *
     * Admin only, not merely staff. These endpoints change what every client
     * is charged and hold the credentials that move money — an agent who can
     * confirm a payment has no business editing the fee that priced it.
     *
     * Secrets go IN and never come back out: reads return a masked tail, and
     * the audit trail redacts the values. See SettingsController.
     */
    Route::middleware('admin')->group(function () {
        Route::prefix('admin/settings')->group(function () {
            Route::get('/', [SettingsController::class, 'index']);
            Route::post('/test-messaging', [SettingsController::class, 'testMessaging']);
            Route::get('/{group}', [SettingsController::class, 'show']);
            Route::put('/{group}', [SettingsController::class, 'update']);
        });

        Route::prefix('admin/payment-providers')->group(function () {
            Route::get('/', [PaymentProviderController::class, 'index']);
            Route::post('/', [PaymentProviderController::class, 'store']);
            Route::get('/{id}', [PaymentProviderController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [PaymentProviderController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [PaymentProviderController::class, 'destroy'])->whereNumber('id');
            Route::post('/{id}/toggle', [PaymentProviderController::class, 'toggle'])->whereNumber('id');
            Route::post('/{id}/test', [PaymentProviderController::class, 'test'])->whereNumber('id');
            Route::post('/{id}/logo', [PaymentProviderController::class, 'uploadLogo'])->whereNumber('id');
        });

        /*
         * Mova Credit administration.
         *
         * `grant` is the only way credit is created by a human, it is capped,
         * it requires a written reason, and it is audited. There is no route
         * here that accepts customer funds.
         */
        Route::prefix('admin/wallets')->group(function () {
            Route::get('/', [WalletAdminController::class, 'index']);
            Route::post('/reconcile', [WalletAdminController::class, 'reconcile']);
            Route::get('/{clientId}', [WalletAdminController::class, 'show'])->whereNumber('clientId');
            Route::post('/{clientId}/grant', [WalletAdminController::class, 'grant'])->whereNumber('clientId');
            Route::post('/{clientId}/status', [WalletAdminController::class, 'setStatus'])->whereNumber('clientId');
        });

        // Runs the real PricingEngine over supplied inputs. Read-only — it
        // computes and returns, it never persists. Backs the simulator on the
        // Algorithme tab. `parameters` returns what the engine is ACTUALLY
        // using, which is config, not settings — see the controller.
        Route::get('/admin/pricing/parameters', [PricingSimulatorController::class, 'parameters']);
        Route::post('/admin/pricing/simulate', [PricingSimulatorController::class, 'simulate']);

        /*
         * ── Analytics ─────────────────────────────────────────────────────
         *
         * One endpoint per dashboard tab, so opening a tab is one request
         * rather than six. Every figure is a SQL aggregate — see the
         * controller's docblock for why that is not negotiable here.
         *
         * `/dash/cards` and `/dash/charts` stay: they are live, the overview
         * below supersedes them, and breaking a working endpoint to remove a
         * duplicate is not a trade worth making mid-migration.
         */
        Route::prefix('admin/analytics')->group(function () {
            Route::get('/overview', [AnalyticsController::class, 'overview']);
            Route::get('/revenue', [AnalyticsController::class, 'revenue']);
            Route::get('/operations', [AnalyticsController::class, 'operations']);
            Route::get('/fleet', [AnalyticsController::class, 'fleet']);
            Route::get('/pass', [AnalyticsController::class, 'pass']);
            Route::get('/clients', [AnalyticsController::class, 'clients']);
        });
    });

    Route::prefix('pass')->group(function () {
        // Public keys only. Safe to intercept, cache or decompile — that is the
        // entire point of choosing Ed25519 over HMAC.
        Route::get('/keys', [PassControlController::class, 'keys']);

        // Downloaded at the depot each morning; `?since=` fetches a delta.
        Route::get('/blacklist', [PassControlController::class, 'blacklist'])
            ->middleware('audit.read:pass_blacklist');
        Route::get('/snapshot', [PassControlController::class, 'snapshot'])
            ->middleware('audit.read:pass_snapshot');

        // Bulk upload of a shift's scans. Idempotent on client_reference, so a
        // retried upload cannot double-count a day's boardings.
        Route::post('/scans/bulk', [PassControlController::class, 'uploadScans']);
    });

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    /*
     * Recruitment and the dashboard are admin-only, matching console/'s own
     * `RequireAdmin` guard on `/jobs` and `/overview`.
     *
     * console/ gates `/jobs` in its router but NOT in its section nav, so an
     * agent currently sees a "Recrutement" tab that bounces them on click. The
     * server side is what actually matters, and it is closed here.
     */
    Route::middleware('admin')->group(function () {
        // Jobs
        Route::post('/jobs/bulk-status', [EmploiController::class, 'bulkStatus']);
        Route::apiResource('jobs', EmploiController::class)->parameters([
            'jobs' => 'emploi' // Indique à Laravel de l'appeler $emploi dans le contrôleur
        ]);

        // Candidates
        Route::post('/candidates/bulk-status', [CandidateController::class, 'bulkStatus']);
        Route::apiResource('candidates', CandidateController::class)->except(['store']);

        // Dashboard — revenue and conversion figures are not agent-level data.
        Route::get('/dash/cards',  [DashboardController::class, 'cards'])
            ->middleware('audit.read:dashboard');   // KPIs
        Route::get('/dash/charts', [DashboardController::class, 'charts']);  // time series
    });

});

/*
 * Removed: `GET /user`, a closure returning `$request->user()` whole.
 *
 * It served no caller — console/ uses `/auth/me`, mobile uses `/app/v1/me` —
 * and it serialised the raw model for whichever guard happened to match,
 * exposing every column the model does not explicitly hide. Both real
 * endpoints go through a resource or a formatter that decides what is public.
 */
