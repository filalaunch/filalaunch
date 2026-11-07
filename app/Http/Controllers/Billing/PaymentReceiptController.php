<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a bank-transfer receipt from private storage — to its owner or to staff allowed to view payments.
 */
class PaymentReceiptController extends Controller
{
    public function __invoke(Payment $payment): StreamedResponse|RedirectResponse
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->guest(filament()->getPanel('admin')->getLoginUrl());
        }

        abort_unless(($payment->user_id === $user->id || $user->can('view', $payment)), 403);
        abort_unless(filled($payment->proof_path) && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }
}
