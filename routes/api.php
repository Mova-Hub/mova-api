<?php

use App\Http\Controllers\Api\ClientNotificationController;
use App\Http\Controllers\Api\ClientOrderController;
use App\Http\Controllers\Api\FcmController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SavedAddressController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\V2\Pass\CardController as PassCardController;
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
        Route::get('/orders/{id}', [ClientOrderController::class, 'show']);

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
    Route::post('register', [AuthController::class, 'register']);
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

Route::prefix('locations')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/autocomplete', [LocationController::class, 'autocomplete']);
    Route::get('/details/{placeId}', [LocationController::class, 'details']);
    Route::get('/reverse-geocode', [LocationController::class, 'reverseGeocode']);
    // Road-following itinerary. POST because the waypoint list is structured.
    Route::post('/directions', [LocationController::class, 'directions']);
});

Route::middleware('auth:sanctum')->group(function () {

    // Staff management (admin only)
    Route::apiResource('staff', StaffController::class);

    // extras
    Route::post('/staff/bulk-status', [StaffController::class, 'bulkStatus']);
    Route::post('/staff/role',        [StaffController::class, 'setRole']); // promote/demote

    // People management
    Route::apiResource('person', PersonController::class);

    // extras
    Route::post('/person/bulk-status',        [PersonController::class, 'bulkStatus']);
    Route::post('/person/role',               [PersonController::class, 'setRole']);
    Route::post('/person/{person}/avatar',    [PersonController::class, 'uploadAvatar']);

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
    Route::get('/clients/{id}', [ClientController::class, 'show']);

    // Order/Lead Management
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}', [OrderController::class, 'update']);

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
    Route::prefix('pass')->group(function () {
        // Public keys only. Safe to intercept, cache or decompile — that is the
        // entire point of choosing Ed25519 over HMAC.
        Route::get('/keys', [PassControlController::class, 'keys']);

        // Downloaded at the depot each morning; `?since=` fetches a delta.
        Route::get('/blacklist', [PassControlController::class, 'blacklist']);
        Route::get('/snapshot', [PassControlController::class, 'snapshot']);

        // Bulk upload of a shift's scans. Idempotent on client_reference, so a
        // retried upload cannot double-count a day's boardings.
        Route::post('/scans/bulk', [PassControlController::class, 'uploadScans']);
    });

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Jobs
    Route::post('/jobs/bulk-status', [EmploiController::class, 'bulkStatus']);
    Route::apiResource('jobs', EmploiController::class)->parameters([
        'jobs' => 'emploi' // Indique à Laravel de l'appeler $emploi dans le contrôleur
    ]);

    Route::post('/candidates/bulk-status', [CandidateController::class, 'bulkStatus']);

    // Candidates
    Route::apiResource('candidates', CandidateController::class)->except(['store']);

    // Dashboard
    Route::get('/dash/cards',  [DashboardController::class, 'cards']);   // KPIs
    Route::get('/dash/charts', [DashboardController::class, 'charts']);  // time series

});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
