<?php

namespace App\Http\Livewire;

use App\Enums\PaymentStatus;
use App\Support\Webpay;
use Illuminate\Support\Facades\Auth;
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

    /** @var string|null 'success'|'failed' after returning from Webpay. */
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

        session(['webpay_pending' => [
            'empresa_id' => $this->customer->id,
            'search' => '',
            'return_to' => 'mi-cuenta',
            'user_id' => Auth::id(),
        ]]);

        $buyOrder = 'PA' . $this->customer->id . '-' . now()->timestamp;
        $sessionId = uniqid('pa-', true);
        $returnUrl = route('webpay.return');

        $response = (new Transaction(Webpay::options()))->create(
            $buyOrder,
            $sessionId,
            $this->customer->charge_amount,
            $returnUrl
        );

        return redirect()->away($response->getUrl() . '?token_ws=' . $response->getToken());
    }

    public function render()
    {
        return view('livewire.my-payment-status');
    }
}
