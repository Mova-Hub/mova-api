<?php

use App\Http\Controllers\Api\V2\Payment\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Invoice document.
 *
 * On the WEB routes, not the API ones, because it is opened by the system
 * browser rather than fetched by the app — it returns HTML, and it has to work
 * with no bearer token, since a browser launched from the app carries none.
 *
 * `signed` is what replaces the token. The app calls the authenticated
 * `/orders/{id}/invoice-link` to mint a URL valid for 30 minutes; without the
 * signature this route refuses. An unsigned public route keyed on the order id
 * would let anyone read every client's itinerary, phone number and price by
 * counting upwards.
 */
Route::get('/invoices/{order}', [InvoiceController::class, 'show'])
    ->middleware('signed')
    ->whereNumber('order')
    ->name('invoice.show');
