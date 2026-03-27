<?php

use App\Http\Controllers\Api\ClientNotificationController;
use App\Http\Controllers\Api\ClientOrderController;
use App\Http\Controllers\Api\FcmController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusController;
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

    // Protected Routes
    // Note: Sanctum automatically determines if the token belongs to a Client or User
    // But we add 'abilities' or middleware checks if we want to be strict.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [ClientAuthController::class, 'me']);
        Route::post('/update-profile', [ClientAuthController::class, 'updateProfile']);
        Route::post('/logout', [ClientAuthController::class, 'logout']);
        // Route::post('/fcm/token', [FcmController::class, 'store']);
        Route::post('/fcm/token', [ClientAuthController::class, 'updateFcmToken']);

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

        // Client sends the order from app
        Route::post('/orders/request', [OrderRequestController::class, 'store']);
        // History
        Route::get('/orders/history', [ClientOrderController::class, 'history']);
        Route::get('/orders/{id}', [ClientOrderController::class, 'show']);

        // Notifications
        Route::get('/notifications', [ClientNotificationController::class, 'index']);
        Route::post('/notifications/read', [ClientNotificationController::class, 'markAsRead']);
    });
});


// Internal Routes

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class,'register']);
    Route::post('login',    [AuthController::class,'login']);
    Route::post('logout',   [AuthController::class,'logout'])->middleware('auth:sanctum');
    Route::get('me',        [AuthController::class,'me'])->middleware('auth:sanctum');
});


// ---------------------------------------------------------
// Public Routes
// ---------------------------------------------------------
// 1. Les candidats peuvent voir les offres ouvertes
Route::get('/jobs/public', [EmploiController::class, 'publicIndex']);

// 2. Les candidats postulent et uploadent leur CV
Route::post('/candidates', [CandidateController::class, 'store']);


Route::middleware('auth:sanctum')->group(function () {

    // Staff management (admin only)
    Route::apiResource('staff', StaffController::class);

    // extras
    Route::post('/staff/bulk-status', [StaffController::class, 'bulkStatus']);
    Route::post('/staff/role',        [StaffController::class, 'setRole']); // promote/demote

    // People management
    Route::apiResource('person', PersonController::class);

    // extras
    Route::post('/person/bulk-status', [PersonController::class, 'bulkStatus']);
    Route::post('/person/role',        [PersonController::class, 'setRole']); // promote/demote

    // Bus management
    Route::apiResource('buses', BusController::class);

    // actions
    Route::post('/buses/{bus}/status',         [BusController::class, 'setStatus']);
    Route::post('/buses/{bus}/assign-driver',  [BusController::class, 'assignDriver']);
    Route::post('/buses/{bus}/assign-conductor',  [BusController::class, 'assignConductor']);
    Route::post('/buses/{bus}/set-operator',   [BusController::class, 'setOperator']);
    Route::post('/buses/bulk-status',          [BusController::class, 'bulkStatus']);

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
