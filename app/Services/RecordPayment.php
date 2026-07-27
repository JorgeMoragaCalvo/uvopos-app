<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SuscriptorPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place a payment is recorded, whatever channel it arrived
 * through: it appends to the `suscriptor_payments` audit trail, extends
 * the company's due date, extends the plan's expiry, and reactivates a
 * suspended account.
 *
 * Both callers go through here — {@see \App\Http\Controllers\WebpayReturnController}
 * for Webpay Plus and {@see \App\Http\Livewire\BankReconciliation} for a
 * reconciled bank transfer — so the two channels can never drift apart
 * on how far a payment moves `proximoPago`.
 */
class RecordPayment
{
    public const SOURCE_WEBPAY = 'webpay';
    public const SOURCE_BANK_TRANSFER = 'bank_transfer';

    /**
     * Fallback renewal length when the plan has none.
     */
    private const DEFAULT_PERIOD_DAYS = 30;

    /**
     * Bounds on `datos_plan.periodo_days`. Production holds 0 (which
     * would leave the due date untouched after a real payment) and
     * 99999999 (which would push it to the year 275000), so anything
     * outside this range is treated as bad data and replaced with the
     * default rather than trusted.
     */
    private const MIN_PERIOD_DAYS = 1;
    private const MAX_PERIOD_DAYS = 1095;

    /**
     * @param  int          $amount             Amount actually paid, in CLP.
     * @param  string       $source             One of the SOURCE_* constants.
     * @param  string|null  $externalReference  Webpay buy order, or the bank movement reference.
     * @param  int          $userId             Staff member or customer who took/confirmed the payment.
     *                                          Not nullable: `suscriptor_payments.user_id` is NOT NULL in
     *                                          production, so a null here fails only after the customer
     *                                          has already been charged.
     */
    public function apply(
        Customer $customer,
        int $amount,
        Carbon $paidAt,
        string $source,
        ?string $externalReference,
        int $userId,
        string $notes
    ): SuscriptorPayment {
        return DB::transaction(function () use ($customer, $amount, $paidAt, $source, $externalReference, $userId, $notes) {
            $plan = $customer->activePlan;
            $oldDueDate = $customer->proximoPago;
            $newDueDate = $this->nextDueDate($oldDueDate, $plan->periodo_days ?? null, $paidAt);

            $payment = SuscriptorPayment::create([
                'amount' => $amount,
                'user_id' => $userId,
                'responsable' => $userId,
                'empresa_id' => $customer->id,
                'plan_id' => $plan->plan_id ?? null,
                'periodo_plan' => $plan->periodo_plan ?? null,
                'notes' => $notes,
                'external_reference' => $externalReference,
                'fecha_pago' => $paidAt,
                'fecha_vencimiento_original' => $oldDueDate,
            ]);

            $customer->proximoPago = $newDueDate;
            $customer->estado = '1';
            $customer->save();

            if ($plan !== null) {
                $plan->fecha_vencimiento = $newDueDate;
                $plan->save();
            }

            return $payment;
        });
    }

    /**
     * A payment always buys a full period. It is added to the current due
     * date when that is still ahead (paying early doesn't forfeit the
     * remaining days) and to the payment date when the account was
     * already overdue (being late doesn't buy back the lapsed ones).
     *
     * The period runs from when the money arrived, not from when it was
     * reconciled: a transfer sitting unreviewed in the queue for a week
     * must not cost the customer a week of service.
     */
    private function nextDueDate(?Carbon $oldDueDate, $periodoDays, Carbon $paidAt): Carbon
    {
        $days = (int) $periodoDays;

        if ($days < self::MIN_PERIOD_DAYS || $days > self::MAX_PERIOD_DAYS) {
            $days = self::DEFAULT_PERIOD_DAYS;
        }

        $from = $paidAt->copy()->startOfDay();

        if ($oldDueDate !== null && $oldDueDate->greaterThan($from)) {
            $from = $oldDueDate->copy()->startOfDay();
        }

        return $from->addDays($days);
    }
}
