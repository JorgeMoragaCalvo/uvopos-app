<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The existing manual-payment audit trail used across the app (staff
 * receipt uploads). The Webpay flow and the bank-reconciliation flow
 * both write into this same table rather than inventing a parallel
 * payment history.
 */
class SuscriptorPayment extends Model
{
    /** Prefix of every Webpay Plus buy order created by this module. */
    public const WEBPAY_REFERENCE_PREFIX = 'PA';

    protected $table = 'suscriptor_payments';

    protected $fillable = [
        'amount',
        'user_id',
        'empresa_id',
        'plan_id',
        'periodo_plan',
        'notes',
        'external_reference',
        'factura_id',
        'fecha_pago',
        'fecha_vencimiento_original',
        'responsable',
        'comprobante_path',
        'comprobante_tipo',
        'comprobante_nombre_original',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'fecha_pago' => 'datetime',
        'fecha_vencimiento_original' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'empresa_id');
    }

    /**
     * Payments taken through Webpay Plus, identified by the buy order
     * this module writes into `external_reference`. Used by the Transbank
     * settlement comparison, which must not count manually recorded or
     * bank-transfer payments as part of a Transbank deposit.
     */
    public function scopeFromWebpay(Builder $query): Builder
    {
        return $query->where('external_reference', 'like', self::WEBPAY_REFERENCE_PREFIX . '%');
    }
}
