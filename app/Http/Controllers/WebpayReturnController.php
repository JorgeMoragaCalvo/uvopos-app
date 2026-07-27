<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\RecordPayment;
use App\Support\Webpay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * Transbank redirects the customer's browser back here (via POST) after
 * Webpay Plus checkout, so this must be a plain route — Livewire can't
 * receive a third-party redirect-form POST directly.
 */
class WebpayReturnController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $pending = session('webpay_pending');
        session()->forget('webpay_pending');

        $token = $request->input('token_ws');
        $search = $pending['search'] ?? '';
        $returnTo = $pending['return_to'] ?? 'payment-alert';

        if (!$token || !$pending) {
            return $this->back($search, 'failed', $returnTo);
        }

        try {
            $response = (new Transaction(Webpay::options()))->commit($token);
        } catch (\Throwable $e) {
            Log::error('Webpay commit failed', ['exception' => $e->getMessage(), 'token' => $token]);

            return $this->back($search, 'failed', $returnTo);
        }

        Log::info('Webpay commit response', (array) $response);

        if (!$response->isApproved()) {
            return $this->back($search, 'failed', $returnTo);
        }

        $this->applySuccessfulPayment($pending, $response);

        return $this->back($search, 'success', $returnTo);
    }

    /**
     * Recording the payment itself lives in {@see RecordPayment}, shared
     * with the bank-reconciliation flow so both channels extend the due
     * date by exactly the same rule.
     */
    private function applySuccessfulPayment(array $pending, $response): void
    {
        $customer = Customer::find($pending['empresa_id']);

        if ($customer === null) {
            return;
        }

        // payWithWebpay() puts the payer in the session payload, and both
        // pages that start a payment are behind `auth`. If it is somehow
        // missing, the customer has still been charged — record the
        // payment against the session user and leave a trail, rather than
        // lose it.
        $userId = (int) ($pending['user_id'] ?? Auth::id() ?? 0);

        if ($userId === 0) {
            Log::warning('Webpay payment recorded without a user', [
                'buy_order' => $response->getBuyOrder(),
                'empresa_id' => $customer->id,
            ]);
        }

        app(RecordPayment::class)->apply(
            $customer,
            (int) $response->getAmount(),
            Carbon::now(),
            RecordPayment::SOURCE_WEBPAY,
            $response->getBuyOrder(),
            $userId,
            'Pago online via Webpay Plus (orden ' . $response->getBuyOrder() . ')'
        );
    }

    /**
     * Back to whichever page started the payment: the customer portal
     * (/mi-cuenta) or the staff lookup page, which needs its search term
     * restored to re-render the customer it was looking at.
     */
    private function back(string $search, string $result, string $returnTo = 'payment-alert'): RedirectResponse
    {
        if ($returnTo === 'mi-cuenta') {
            return redirect()->route('mi-cuenta', ['payment' => $result]);
        }

        return redirect()->route('payment-alert', ['search' => $search, 'payment' => $result]);
    }
}
