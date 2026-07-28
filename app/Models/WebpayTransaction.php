<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Webpay Plus checkout we started, from the moment before the browser
 * leaves for Transbank until the return lands.
 *
 * The lifecycle is one-way and terminal:
 *
 *   pending ─┬─> authorized  (committed and approved — money moved)
 *            ├─> declined    (committed, card refused)
 *            ├─> aborted     (customer backed out, or the form timed out)
 *            └─> failed      (we could not reach Transbank to commit)
 *
 * Only `authorized` has a financial effect, and the move out of `pending`
 * is done with a conditional UPDATE (see WebpayReturnController::claim())
 * so a replayed return POST cannot record a second payment.
 */
class WebpayTransaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_FAILED = 'failed';

    protected $table = 'webpay_transactions';

    protected $fillable = [
        'buy_order',
        'session_id',
        'empresa_id',
        'user_id',
        'amount',
        'search',
        'return_to',
        'status',
        'token',
        'suscriptor_payment_id',
        'response_code',
        'committed_at',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'empresa_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SuscriptorPayment::class, 'suscriptor_payment_id');
    }

    /** Amount in whole pesos — the column is decimal for schema parity. */
    public function getAmountClpAttribute(): int
    {
        return (int) round((float) $this->amount);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * The buy order sent to Transbank. Webpay Plus caps it at 26 chars,
     * and it has to be unique per transaction — a plain
     * "PA{id}-{timestamp}" repeats when the same customer is charged
     * twice within one second, which Transbank rejects.
     */
    public static function makeBuyOrder(int $empresaId): string
    {
        return substr('PA' . $empresaId . '-' . now()->timestamp . '-' . strtoupper(bin2hex(random_bytes(2))), 0, 26);
    }
}
