<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The existing manual-payment audit trail used across the app (staff
 * receipt uploads). The Webpay flow writes into this same table rather
 * than inventing a parallel payment history.
 */
class SuscriptorPayment extends Model
{
    protected $table = 'suscriptor_payments';

    protected $fillable = [
        'amount',
        'user_id',
        'empresa_id',
        'plan_id',
        'periodo_plan',
        'notes',
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
}
