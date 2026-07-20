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
 * Read-only view over the production `empresas` table. The subscription
 * due date lives in `proximoPago`; RUTs are stored as "77353398-9"
 * (dash, no dots), so lookups compare against a stripped-down copy.
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

        return PaymentStatus::fromDaysPastDue($days ?? 0);
    }

    public function getFormattedRutAttribute(): string
    {
        return Rut::format($this->rut);
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
