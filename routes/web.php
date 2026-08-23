<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * No document routes live here.
 *
 * The invoice was briefly served from this file as an HTML page. It is now a
 * PDF download on the API (`/api/app/v1/invoices/{order}`, signed), because
 * this backend serves data and files — not views. The Blade template it renders
 * from is an internal detail of dompdf and is not reachable as a URL.
 */
