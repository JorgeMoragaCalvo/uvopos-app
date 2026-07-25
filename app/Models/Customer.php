<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Rut;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * View over the production `empresas` table. The subscription due date
 * lives in `proximoPago`; RUTs are stored as "77353398-9" (dash, no
 * dots), so lookups compare against a stripped-down copy. Mostly
 * read-only, except for the Webpay/suspend flow, which writes
 * `proximoPago` and `estado`.
 */
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'empresas';

    protected $casts = [
        'proximoPago' => 'date',
    ];

    public function getNameAttribute(): string
    {
        return $this->nombre_fantasia ?: $this->RazonSocial;
    }

    public function getPaymentDateAttribute(): ?Carbon
    {
        return $this->proximoPago;
    }

    /**
     * Days elapsed since the payment date. Negative when the payment
     * is not yet due; null when no payment date is set.
     */
    public function getDaysPastDueAttribute(): ?int
    {
        if ($this->payment_date === null) {
            return null;
        }

        return (int) $this->payment_date->startOfDay()->diffInDays(Carbon::today(), false);
    }

    /**
     * One of the PaymentStatus constants. A customer without a payment
     * date is considered on time.
     */
    public function getPaymentStatusAttribute(): string
    {
        $days = $this->days_past_due;

        if ($days === null) {
            return PaymentStatus::ON_TIME;
        }

        return PaymentStatus::fromDaysPastDue($days);
    }

    public function getFormattedRutAttribute(): string
    {
        return Rut::format($this->rut);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->estado === '1';
    }

    /**
     * Human-readable subscription plan. `empresas.tipoPlan` is free text
     * in production (13 distinct values, one known misspelling, some
     * blanks), so unrecognized values are shown as stored rather than
     * collapsed into a fixed set.
     */
    public function getPlanTypeAttribute(): string
    {
        $plan = trim((string) $this->tipoPlan);

        if ($plan === '') {
            return 'Sin plan';
        }

        // Known data-entry typo in db2026.empresas.
        if (mb_strtolower($plan) === 'menusal') {
            return 'Mensual';
        }

        return $plan;
    }

    /**
     * The company's current plan/pricing row, used to charge via
     * Webpay and to extend the due date on a successful payment.
     */
    public function activePlan(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DatosPlan::class, 'empresa_id')->where('estado', 1)->latestOfMany();
    }

    /**
     * Amount to charge via Webpay: the plan price plus any hardware
     * installment still owed. Null if there is no active plan on file.
     */
    public function getChargeAmountAttribute(): ?int
    {
        if ($this->activePlan === null) {
            return null;
        }

        return (int) $this->activePlan->monto_plan + (int) ($this->activePlan->monto_hardware ?: 0);
    }

    /**
     * True once staff are allowed to suspend the account: overdue by
     * more than the configured grace period, and not already suspended.
     * Suspension itself is always a manual, staff-confirmed action.
     */
    public function getIsSuspendableAttribute(): bool
    {
        $graceDays = (int) config('payment_alert.overdue_grace_days', 3);

        return $this->is_active && ($this->days_past_due ?? 0) > $graceDays;
    }

    /**
     * Days left before the account becomes suspendable. Only
     * meaningful while overdue and not yet suspendable.
     */
    public function getDaysUntilSuspendableAttribute(): ?int
    {
        if ($this->days_past_due === null || $this->days_past_due <= 0) {
            return null;
        }

        $graceDays = (int) config('payment_alert.overdue_grace_days', 3);

        return max(0, $graceDays - $this->days_past_due + 1);
    }

    /**
     * SQL mirror of {@see PaymentStatus::fromDaysPastDue()}, for the
     * admin list, which has to filter and count by status without
     * loading every row. `payment_status` is a PHP accessor, so the
     * status is expressed here as a range on `proximoPago`
     * (days_past_due = today - proximoPago):
     *
     *   overdue  : days > 0        -> proximoPago < today
     *   due_soon : -D <= days <= 0 -> today <= proximoPago <= today + D
     *   on_time  : days <= -(D+1)  -> proximoPago > today + D, or NULL
     *
     * Keep this in sync with the accessor — changing one without the
     * other makes the badge and the filter disagree.
     */
    public function scopeWithPaymentStatus(Builder $query, string $status): Builder
    {
        $dueSoonDays = (int) config('payment_alert.due_soon_days', 3);
        $today = Carbon::today();
        $dueSoonEnd = $today->copy()->addDays($dueSoonDays);

        if ($status === PaymentStatus::OVERDUE) {
            return $query->whereDate('proximoPago', '<', $today);
        }

        if ($status === PaymentStatus::DUE_SOON) {
            return $query->whereDate('proximoPago', '>=', $today)
                ->whereDate('proximoPago', '<=', $dueSoonEnd);
        }

        if ($status === PaymentStatus::ON_TIME) {
            return $query->where(function (Builder $q) use ($dueSoonEnd) {
                $q->whereDate('proximoPago', '>', $dueSoonEnd)
                    ->orWhereNull('proximoPago');
            });
        }

        return $query;
    }

    /**
     * Most urgent first: the furthest overdue at the top, companies
     * without a payment date last.
     */
    public function scopeOrderByUrgency(Builder $query): Builder
    {
        return $query->orderByRaw('proximoPago IS NULL')->orderBy('proximoPago');
    }

    /**
     * Look a customer up by numeric ID or by RUT (any format).
     */
    public function scopeByRutOrId(Builder $query, string $term): Builder
    {
        if (ctype_digit($term) && strlen($term) <= 6) {
            return $query->where('id', (int) $term);
        }

        return $query->whereRaw(
            "UPPER(REPLACE(REPLACE(REPLACE(rut, '.', ''), '-', ''), ' ', '')) = ?",
            [Rut::normalize($term)]
        );
    }
}
