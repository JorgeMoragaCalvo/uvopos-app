<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line from an imported cartola and what was decided about it.
 *
 * The lifecycle is deliberately one-way and staff-driven:
 *
 *   unmatched ─┬─> suggested ──> matched   (staff confirmed a company)
 *              └─────────────── ignored    (not a subscription payment)
 *
 * Only `matched` has a financial effect, and only a staff click produces
 * it — the match engine never advances a movement past `suggested`.
 */
class BankMovement extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_IGNORED = 'ignored';

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    protected $table = 'bank_movements';

    protected $fillable = [
        'bank_statement_id',
        'posted_at',
        'description',
        'reference',
        'amount',
        'direction',
        'counterparty_rut',
        'row_hash',
        'status',
        'empresa_id',
        'suscriptor_payment_id',
        'match_confidence',
        'match_reason',
        'reconciled_by',
        'reconciled_at',
    ];

    protected $casts = [
        'posted_at' => 'date',
        'reconciled_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'empresa_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SuscriptorPayment::class, 'suscriptor_payment_id');
    }

    /** Money in. Only credits can ever settle a subscription. */
    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_CREDIT);
    }

    /** Still needs a human decision. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_UNMATCHED, self::STATUS_SUGGESTED]);
    }

    public function getIsResolvedAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_MATCHED, self::STATUS_IGNORED], true);
    }

    public function getAmountClpAttribute(): int
    {
        return (int) round((float) $this->amount);
    }
}
