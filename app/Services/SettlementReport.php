<?php

namespace App\Services;

use App\Models\BankMovement;
use App\Models\SuscriptorPayment;
use Carbon\Carbon;

/**
 * Compares what the app charged through Webpay against what Transbank
 * actually deposited.
 *
 * The customer-facing half of reconciliation answers "who paid us?"; this
 * half answers "did the money arrive?". They are different failures: a
 * Webpay transaction can be approved, recorded, and still never settle —
 * a reversal, a chargeback, or a commit the app recorded twice. Nothing
 * else in the module would notice.
 *
 * This report is read-only. It never writes payments: the charges it
 * compares against are already in `suscriptor_payments`, so creating
 * more would double-count them.
 */
class SettlementReport
{
    /**
     * One row per Transbank deposit, newest first.
     *
     * @return array<int,array{
     *     movement: BankMovement,
     *     charged: int,
     *     deposited: int,
     *     expected: int,
     *     difference: int,
     *     matches: bool,
     *     charge_date: string,
     *     payments: \Illuminate\Support\Collection
     * }>
     */
    public function forDeposits(int $limit = 30): array
    {
        $lag = (int) config('bank_reconciliation.settlement_lag_days', 2);
        $commission = (float) config('bank_reconciliation.transbank_commission_pct', 0);
        $tolerance = (int) config('bank_reconciliation.settlement_tolerance_clp', 100);

        $deposits = BankMovement::query()
            ->credits()
            ->where('match_reason', 'Liquidación Transbank')
            ->orderByDesc('posted_at')
            ->limit($limit)
            ->get();

        return $deposits->map(function (BankMovement $deposit) use ($lag, $commission, $tolerance) {
            $chargeDate = $deposit->posted_at->copy()->subDays($lag);
            $payments = $this->webpayPaymentsOn($chargeDate);

            $charged = (int) $payments->sum(function (SuscriptorPayment $payment) {
                return (int) round((float) $payment->amount);
            });

            $expected = (int) round($charged * (1 - $commission / 100));
            $deposited = $deposit->amount_clp;
            $difference = $deposited - $expected;

            return [
                'movement' => $deposit,
                'charge_date' => $chargeDate->toDateString(),
                'charged' => $charged,
                'expected' => $expected,
                'deposited' => $deposited,
                'difference' => $difference,
                'matches' => abs($difference) <= $tolerance,
                'payments' => $payments,
            ];
        })->all();
    }

    /**
     * Webpay payments recorded on a given day. `fecha_pago` is a datetime,
     * so this compares the date part — and only payments carrying a
     * Webpay buy order count, because a manually recorded payment or a
     * reconciled transfer was never part of a Transbank deposit.
     */
    private function webpayPaymentsOn(Carbon $date): \Illuminate\Support\Collection
    {
        return SuscriptorPayment::query()
            ->fromWebpay()
            ->whereDate('fecha_pago', $date->toDateString())
            ->orderBy('fecha_pago')
            ->get();
    }

    /**
     * Totals for the page header: how many deposits reconcile cleanly and
     * how much money is unaccounted for across the ones that don't.
     *
     * @return array{deposits: int, mismatched: int, difference: int}
     */
    public function summary(int $limit = 30): array
    {
        $rows = $this->forDeposits($limit);
        $mismatched = array_filter($rows, function (array $row) {
            return !$row['matches'];
        });

        return [
            'deposits' => count($rows),
            'mismatched' => count($mismatched),
            'difference' => (int) array_sum(array_column($mismatched, 'difference')),
        ];
    }
}
