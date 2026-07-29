<?php

namespace App\Services;

use App\Models\BankMovement;
use App\Models\Customer;
use App\Models\SuscriptorPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a reconciled deposit into a payment. The one place a bank movement
 * becomes money, whether a staff member confirmed it in the review queue
 * ({@see \App\Http\Livewire\BankReconciliation::confirmMatch()}) or the
 * importer confirmed it on its own ({@see ImportCartola}).
 *
 * The two callers share this for the same reason both payment channels
 * share {@see RecordPayment}: the guard against paying twice has to be
 * identical on every path, or one of them will drift and stop guarding.
 *
 * That guard is the conditional UPDATE below. The movement is *claimed*
 * inside the transaction, and a caller who finds nothing to claim — a
 * double click, a stale tab, a second staff member, a re-import racing the
 * queue — records nothing and gets null back.
 */
class ConfirmMovement
{
    /**
     * @param  int   $userId  Staff member confirming, or the one who uploaded
     *                        the cartola when $auto. Not nullable:
     *                        `suscriptor_payments.user_id` is NOT NULL.
     * @param  bool  $auto    True when nobody reviewed this — the importer
     *                        decided it from the match signals.
     *
     * @return SuscriptorPayment|null  Null when the movement was already resolved.
     */
    public function confirm(BankMovement $movement, Customer $customer, int $userId, bool $auto = false): ?SuscriptorPayment
    {
        return DB::transaction(function () use ($movement, $customer, $userId, $auto) {
            $claimed = BankMovement::whereKey($movement->id)
                ->whereNotIn('status', [BankMovement::STATUS_MATCHED, BankMovement::STATUS_IGNORED])
                ->update([
                    'status' => BankMovement::STATUS_MATCHED,
                    'empresa_id' => $customer->id,
                    // Nobody made this decision, so nobody is credited with
                    // it. `auto_confirmed` is what tells this apart from the
                    // Transbank payout tag, which also leaves this null.
                    'reconciled_by' => $auto ? null : $userId,
                    'reconciled_at' => now(),
                    'auto_confirmed' => $auto,
                ]);

            if ($claimed === 0) {
                return null;
            }

            $payment = app(RecordPayment::class)->apply(
                $customer,
                $movement->amount_clp,
                Carbon::parse($movement->posted_at),
                RecordPayment::SOURCE_BANK_TRANSFER,
                $movement->reference,
                $userId,
                $this->notes($movement, $auto)
            );

            BankMovement::whereKey($movement->id)->update(['suscriptor_payment_id' => $payment->id]);

            return $payment;
        });
    }

    private function notes(BankMovement $movement, bool $auto): string
    {
        $date = $movement->posted_at->format('d/m/Y');

        return $auto
            ? 'Transferencia bancaria conciliada automáticamente (' . $date . ')'
            : 'Transferencia bancaria conciliada (' . $date . ')';
    }
}
