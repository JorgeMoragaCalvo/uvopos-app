<?php

namespace App\Http\Livewire;

use App\Enums\PaymentStatus;
use App\Models\WebpayTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * Customer-facing counterpart of {@see PaymentAlert}: shows the logged-in
 * user's own company payment status and lets them pay with Webpay.
 *
 * No search box and no suspend/reactivate — those stay staff-only on
 * /payment-alert. The company is always taken from the session, never
 * from user input.
 */
class MyPaymentStatus extends Component
{
    /** @var \App\Models\Customer|null */
    public $customer = null;

    /** @var string|null success|declined|aborted|error|failed after returning from Webpay. */
    public $paymentResult = null;

    public function mount(): void
    {
        $this->customer = Auth::user()->customer;
        $this->paymentResult = request()->query('payment');
    }

    /**
     * True while the customer may pay. Unlike the staff view this does
     * not require an active account: a suspended customer paying is how
     * they get reactivated (see WebpayReturnController).
     */
    public function getCanPayProperty(): bool
    {
        return $this->customer !== null
            && $this->customer->charge_amount !== null
            && in_array($this->customer->payment_status, [PaymentStatus::DUE_SOON, PaymentStatus::OVERDUE], true);
    }

    /**
     * Creates a Webpay Plus transaction for the logged-in user's company
     * and redirects the browser to Transbank's payment page.
     */
    public function payWithWebpay()
    {
        if (!$this->can_pay) {
            return;
        }

        // Persisted rather than kept in the session — see the same block
        // in PaymentAlert::payWithWebpay(). `return_to` is what sends the
        // customer back here instead of the staff page.
        $transaction = WebpayTransaction::create([
            'buy_order' => WebpayTransaction::makeBuyOrder($this->customer->id),
            'session_id' => uniqid('pa-', true),
            'empresa_id' => $this->customer->id,
            'user_id' => Auth::id(),
            'amount' => $this->customer->charge_amount,
            'search' => '',
            'return_to' => 'mi-cuenta',
        ]);

        try {
            $response = app(Transaction::class)->create(
                $transaction->buy_order,
                $transaction->session_id,
                $transaction->amount_clp,
                route('webpay.return')
            );
        } catch (\Throwable $e) {
            Log::error('Webpay transaction create failed', [
                'exception' => $e->getMessage(),
                'buy_order' => $transaction->buy_order,
            ]);

            $transaction->update(['status' => WebpayTransaction::STATUS_FAILED]);

            $this->paymentResult = 'error';

            return;
        }

        return redirect()->away($response->getUrl() . '?token_ws=' . $response->getToken());
    }

    public function render()
    {
        return view('livewire.my-payment-status');
    }
}
