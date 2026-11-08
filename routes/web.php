<?php

use App\Http\Controllers\Billing\PaymentReceiptController;
use App\Http\Controllers\Teams\AcceptTeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Bank-transfer receipts (private files) — owner or staff with payments.view.
Route::get('/billing/receipts/{payment}', PaymentReceiptController::class)->name('billing.receipt');

// Team invitation links (only active when teams are on).
Route::get('/team-invitations/{token}', AcceptTeamInvitationController::class)->name('team-invitations.accept');
