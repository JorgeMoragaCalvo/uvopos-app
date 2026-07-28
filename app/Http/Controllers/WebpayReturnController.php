<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\WebpayTransaction;
use App\Services\RecordPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * Transbank redirects the customer's browser back here (via POST) after
 * Webpay Plus checkout, so this must be a plain route — Livewire can't
 * receive a third-party redirect-form POST directly.
 *
 * Two properties of that POST shape everything below:
 *
 *  - It is *cross-site*. It carries no CSRF token (hence the exemption in
 *    VerifyCsrfToken) and no SameSite=Lax session cookie, so none of the
 *    state from the page that started the payment is available here. The
 *    pending payment is looked up in `webpay_transactions` by buy order,
 *    which travels in the request body or comes back from the commit.
 *  - It has four shapes, not one. Only the first is a payment:
 *
 *      token_ws                          -> completed checkout, commit it
 *      token_ws + TBK_TOKEN              -> aborted after authorization
 *      TBK_TOKEN + TBK_ORDEN_COMPRA      -> customer cancelled
 *      TBK_ID_SESION + TBK_ORDEN_COMPRA  -> the form timed out
 *
 *    Committing the second shape would record a payment the customer
 *    explicitly backed out of, so the presence of TBK_TOKEN vetoes the
 *    commit regardless of what else arrived.
 */
class WebpayReturnController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $token = $request->input('token_ws');
        $abortToken = $request->input('TBK_TOKEN');
        $buyOrder = $request->input('TBK_ORDEN_COMPRA');

        // The customer backed out, or the payment form expired. Neither
        // is a payment; nothing is committed and nothing is written.
        if ($abortToken !== null || ($token === null && $buyOrder !== null)) {
            return $this->handleAbandoned($buyOrder, $abortToken !== null);
        }

        if (!$token) {
            // Not a shape Transbank documents — a stale bookmark, a bot,
            // or a return we have failed to parse. Previously silent.
            Log::warning('Webpay return with no usable token', [
                'params' => array_keys($request->all()),
            ]);

            return $this->back(null, 'failed');
        }

        try {
            $response = app(Transaction::class)->commit($token);
        } catch (\Throwable $e) {
            // We cannot tell from here whether the charge went through.
            // The buy order is unknown (it comes back with the commit), so
            // the token is the only handle for manual investigation.
            Log::error('Webpay commit failed', [
                'exception' => $e->getMessage(),
                'token' => $token,
            ]);

            return $this->back(null, 'error');
        }

        $transaction = WebpayTransaction::where('buy_order', $response->getBuyOrder())->first();

        Log::info('Webpay commit', [
            'buy_order' => $response->getBuyOrder(),
            'response_code' => $response->getResponseCode(),
            'status' => $response->getStatus(),
            'amount' => $response->getAmount(),
            'known' => $transaction !== null,
        ]);

        if ($transaction === null) {
            // Transbank committed a transaction we have no record of
            // starting. If it was approved the customer has been charged
            // and we cannot safely guess who for.
            Log::error('Webpay commit for an unknown buy order', [
                'buy_order' => $response->getBuyOrder(),
                'approved' => $response->isApproved(),
                'amount' => $response->getAmount(),
            ]);

            return $this->back(null, 'failed');
        }

        if (!$response->isApproved()) {
            $this->close($transaction, WebpayTransaction::STATUS_DECLINED, $token, $response);

            return $this->back($transaction, 'declined');
        }

        if ((int) $response->getAmount() !== $transaction->amount_clp) {
            // Should be impossible: the amount is ours, set at create()
            // and echoed back. If it ever differs, recording a payment
            // for either figure is a guess — flag it for a human instead.
            // The deposit still surfaces in the Transbank settlement tab.
            Log::error('Webpay amount mismatch — payment NOT recorded', [
                'buy_order' => $response->getBuyOrder(),
                'expected' => $transaction->amount_clp,
                'committed' => $response->getAmount(),
                'empresa_id' => $transaction->empresa_id,
            ]);

            $this->close($transaction, WebpayTransaction::STATUS_FAILED, $token, $response);

            return $this->back($transaction, 'error');
        }

        $this->applySuccessfulPayment($transaction, $token, $response);

        return $this->back($transaction, 'success');
    }

    /**
     * Cancelled or timed out. Both arrive without a committable token, so
     * there is nothing to reverse — just close the row so a later replay
     * can't be mistaken for a fresh attempt.
     */
    private function handleAbandoned(?string $buyOrder, bool $cancelled): RedirectResponse
    {
        $transaction = $buyOrder === null
            ? null
            : WebpayTransaction::where('buy_order', $buyOrder)->first();

        Log::info('Webpay checkout abandoned', [
            'buy_order' => $buyOrder,
            'reason' => $cancelled ? 'cancelled by customer' : 'payment form timed out',
            'known' => $transaction !== null,
        ]);

        if ($transaction !== null && $transaction->isPending()) {
            WebpayTransaction::whereKey($transaction->id)
                ->where('status', WebpayTransaction::STATUS_PENDING)
                ->update([
                    'status' => WebpayTransaction::STATUS_ABORTED,
                    'committed_at' => now(),
                ]);
        }

        return $this->back($transaction, 'aborted');
    }

    /**
     * Recording the payment itself lives in {@see RecordPayment}, shared
     * with the bank-reconciliation flow so both channels extend the due
     * date by exactly the same rule.
     *
     * The row is claimed with a conditional UPDATE inside the transaction
     * — the same guard BankReconciliation::confirmMatch() uses — so a
     * replayed return POST, a refreshed tab or two concurrent requests
     * record the payment exactly once.
     */
    private function applySuccessfulPayment(WebpayTransaction $transaction, string $token, $response): void
    {
        $customer = Customer::find($transaction->empresa_id);

        if ($customer === null) {
            // The card was charged and there is nobody to credit. Loud,
            // because this needs a manual refund or a manual payment row.
            Log::error('Webpay payment for a customer that no longer exists', [
                'buy_order' => $transaction->buy_order,
                'empresa_id' => $transaction->empresa_id,
                'amount' => $response->getAmount(),
            ]);

            $this->close($transaction, WebpayTransaction::STATUS_FAILED, $token, $response);

            return;
        }

        DB::transaction(function () use ($transaction, $customer, $token, $response) {
            $claimed = WebpayTransaction::whereKey($transaction->id)
                ->where('status', WebpayTransaction::STATUS_PENDING)
                ->update([
                    'status' => WebpayTransaction::STATUS_AUTHORIZED,
                    'token' => $token,
                    'response_code' => $response->getResponseCode(),
                    'committed_at' => now(),
                ]);

            // Already settled by an earlier delivery of this same return.
            if ($claimed === 0) {
                return;
            }

            $payment = app(RecordPayment::class)->apply(
                $customer,
                (int) $response->getAmount(),
                Carbon::now(),
                RecordPayment::SOURCE_WEBPAY,
                $transaction->buy_order,
                (int) $transaction->user_id,
                'Pago online via Webpay Plus (orden ' . $transaction->buy_order . ')'
            );

            WebpayTransaction::whereKey($transaction->id)
                ->update(['suscriptor_payment_id' => $payment->id]);
        });
    }

    /**
     * Move a pending transaction to a terminal non-paying state.
     */
    private function close(WebpayTransaction $transaction, string $status, ?string $token, $response = null): void
    {
        WebpayTransaction::whereKey($transaction->id)
            ->where('status', WebpayTransaction::STATUS_PENDING)
            ->update([
                'status' => $status,
                'token' => $token,
                'response_code' => $response === null ? null : $response->getResponseCode(),
                'committed_at' => now(),
            ]);
    }

    /**
     * Back to whichever page started the payment: the customer portal
     * (/mi-cuenta) or the staff lookup page, which needs its search term
     * restored to re-render the customer it was looking at.
     *
     * Both come off the transaction row rather than the session, which
     * does not survive Transbank's cross-site POST. With no row there is
     * no context at all, so fall back to the staff page — a non-admin
     * landing there is redirected on to their own account.
     */
    private function back(?WebpayTransaction $transaction, string $result): RedirectResponse
    {
        if ($transaction !== null && $transaction->return_to === 'mi-cuenta') {
            return redirect()->route('mi-cuenta', ['payment' => $result]);
        }

        return redirect()->route('payment-alert', [
            'search' => $transaction->search ?? '',
            'payment' => $result,
        ]);
    }
}
