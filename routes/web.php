<?php

use App\Http\Controllers\Billing\PaymentReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Bank-transfer receipts (private files) — owner or staff with payments.view.
Route::get('/billing/receipts/{payment}', PaymentReceiptController::class)->name('billing.receipt');
