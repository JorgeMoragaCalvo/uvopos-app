<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line from an imported cartola and what was decided about it.
 *
 * The lifecycle is one-way:
 *
 *   unmatched ─┬─> suggested ──> matched   (a company was confirmed)
 *              └─────────────── ignored    (not a subscription payment)
 *
 * Only `matched` has a financial effect. Almost always that takes a staff
 * click; the one exception is a deposit carrying the payer's RUT and the
 * exact plan amount with no rival candidate, which
 * {@see \App\Services\ImportCartola} confirms itself and flags
 * `auto_confirmed`. Once `suscriptor_payment_id` is set there is no way
 * back — see {@see \App\Http\Livewire\BankReconciliation::returnToQueue()}.
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
        'auto_confirmed',
    ];

    protected $casts = [
        'posted_at' => 'date',
        'reconciled_at' => 'datetime',
        'auto_confirmed' => 'boolean',
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

    /**
     * Still needs a human decision. This is the "to do" count — the tab
     * badge and the one on /payment-alert — not what the review screen
     * lists; see {@see scopeQueue()}.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_UNMATCHED, self::STATUS_SUGGESTED]);
    }

    /**
     * What the review screen shows by default: everything still to decide,
     * plus the deposits the import reconciled by itself. Those need no
     * action, but they are the cartola's work and staff have to see it
     * happen — an automatic payment nobody can find is worse than no
     * automation.
     *
     * Staff confirmations and Transbank payout tags stay out: one has
     * already been reviewed, the other belongs to the settlement tab.
     */
    public function scopeQueue(Builder $query): Builder
    {
        // Grouped: an unparenthesised orWhere would escape any other
        // constraint the caller put on the query.
        return $query->where(function (Builder $inner) {
            $inner->whereIn('status', [self::STATUS_UNMATCHED, self::STATUS_SUGGESTED])
                ->orWhere('auto_confirmed', true);
        });
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
