<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A company's plan/subscription record — pricing and renewal length
 * used to charge via Webpay and to extend the due date on payment.
 * Read/write view over the production `datos_plan` table (no migration
 * for it in production; the local migration only mirrors the columns
 * this feature touches).
 */
class DatosPlan extends Model
{
    protected $table = 'datos_plan';

    protected $casts = [
        'fecha_vencimiento' => 'date',
    ];
}
