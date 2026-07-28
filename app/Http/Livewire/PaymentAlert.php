<?php

namespace App\Http\Livewire;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\User;
use App\Models\WebpayTransaction;
use App\Support\Rut;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;
use Transbank\Webpay\WebpayPlus\Transaction;

class PaymentAlert extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** @var string Customer ID or RUT typed by the user. */
    public $search = '';

    /** @var string One of the PaymentStatus constants, or '' for all. */
    public $statusFilter = '';

    /** @var \App\Models\Customer|null */
    public $customer = null;

    /** @var bool True after a lookup that found no customer. */
    public $notFound = false;

    /** @var string|null success|declined|aborted|error|failed after returning from Webpay. */
    public $paymentResult = null;

    /** @var bool Awaiting the staff confirmation click for suspend(). */
    public $confirmingSuspend = false;

    protected $rules = [
        'search' => 'required|string|max:20',
    ];

    protected $messages = [
        'search.required' => 'Ingrese un RUT o ID de cliente.',
    ];

    protected $queryString = [
        'statusFilter' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->paymentResult = request()->query('payment');

        $search = request()->query('search');

        if ($search) {
            $this->search = $search;
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $this->validate();

        $term = trim($this->search);

        // Anything that is not a short numeric ID is treated as a RUT
        // and must have a valid check digit before hitting the database.
        if (!(ctype_digit($term) && strlen($term) <= 6) && !Rut::isValid($term)) {
            $this->customer = null;
            $this->notFound = false;
            $this->addError('search', 'El RUT ingresado no es válido.');

            return;
        }

        $this->customer = Customer::byRutOrId($term)->first();
        $this->notFound = $this->customer === null;
    }

    /**
     * Opens a row from the list in the detail panel. Goes through
     * lookup() rather than assigning $customer directly so that $search
     * stays populated — payWithWebpay() puts it in the webpay_pending
     * payload, and WebpayReturnController redirects back to ?search=.
     */
    public function selectCustomer(int $id): void
    {
        $this->search = (string) $id;
        $this->paymentResult = null;
        $this->confirmingSuspend = false;
        $this->resetErrorBag();
        $this->lookup();
    }

    public function filterByStatus(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->customer = null;
        $this->notFound = false;
        $this->paymentResult = null;
        $this->confirmingSuspend = false;
        $this->resetErrorBag();
    }

    /**
     * Creates a Webpay Plus transaction for the looked-up customer and
     * redirects the browser to Transbank's payment page. Only allowed
     * while the account is due soon or overdue and has a priced plan.
     */
    public function payWithWebpay()
    {
        if ($this->customer === null || $this->customer->charge_amount === null) {
            return;
        }

        if (!in_array($this->customer->payment_status, [PaymentStatus::DUE_SOON, PaymentStatus::OVERDUE], true)) {
            return;
        }

        // Persisted, not put in the session: Transbank returns the
        // customer with a cross-site POST, which carries no SameSite=Lax
        // cookie, so the return leg has to find this by buy order. It also
        // carries the payer — suscriptor_payments.user_id is NOT NULL in
        // production and the return lands unauthenticated.
        $transaction = WebpayTransaction::create([
            'buy_order' => WebpayTransaction::makeBuyOrder($this->customer->id),
            'session_id' => uniqid('pa-', true),
            'empresa_id' => $this->customer->id,
            'user_id' => Auth::id(),
            'amount' => $this->customer->charge_amount,
            'search' => $this->search,
            'return_to' => 'payment-alert',
        ]);

        try {
            $response = app(Transaction::class)->create(
                $transaction->buy_order,
                $transaction->session_id,
                $transaction->amount_clp,
                route('webpay.return')
            );
        } catch (\Throwable $e) {
            // Transbank unreachable, or credentials rejected. Nothing was
            // charged; without this the exception reaches Livewire as a 500.
            Log::error('Webpay transaction create failed', [
                'exception' => $e->getMessage(),
                'buy_order' => $transaction->buy_order,
            ]);

            $transaction->update(['status' => WebpayTransaction::STATUS_FAILED]);

            $this->addError('search', 'No se pudo iniciar el pago. Intente nuevamente en unos minutos.');

            return;
        }

        return redirect()->away($response->getUrl() . '?token_ws=' . $response->getToken());
    }

    public function confirmSuspend(): void
    {
        $this->confirmingSuspend = true;
    }

    public function cancelSuspend(): void
    {
        $this->confirmingSuspend = false;
    }

    /**
     * Manual, staff-confirmed suspension — never automatic. Only allowed
     * once the customer is past the overdue grace period.
     */
    public function suspend(): void
    {
        if ($this->customer === null || !$this->customer->is_suspendable || !$this->confirmingSuspend) {
            return;
        }

        $this->customer->estado = '0';
        $this->customer->save();
        User::where('empresa_id', $this->customer->id)->update(['status' => 0]);

        $this->confirmingSuspend = false;
        $this->customer->refresh();
    }

    /**
     * Manual staff override to reactivate without waiting for a Webpay
     * payment (e.g. the customer paid by another channel).
     */
    public function reactivate(): void
    {
        if ($this->customer === null || $this->customer->is_active) {
            return;
        }

        $this->customer->estado = '1';
        $this->customer->save();
        User::where('empresa_id', $this->customer->id)->update(['status' => 1]);

        $this->customer->refresh();
    }

    public function render()
    {
        return view('livewire.payment-alert', [
            'customers' => Customer::query()
                ->when($this->statusFilter !== '', function ($query) {
                    return $query->withPaymentStatus($this->statusFilter);
                })
                ->orderByUrgency()
                ->paginate(15),
            'counts' => [
                PaymentStatus::ON_TIME  => Customer::withPaymentStatus(PaymentStatus::ON_TIME)->count(),
                PaymentStatus::DUE_SOON => Customer::withPaymentStatus(PaymentStatus::DUE_SOON)->count(),
                PaymentStatus::OVERDUE  => Customer::withPaymentStatus(PaymentStatus::OVERDUE)->count(),
            ],
        ]);
    }
}
