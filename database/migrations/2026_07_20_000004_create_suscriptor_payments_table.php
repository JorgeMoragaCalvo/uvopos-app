<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of the production `suscriptor_payments` table — the
 * existing manual-payment audit trail this app's Webpay flow writes
 * into, instead of inventing a parallel history.
 *
 * Two columns here intentionally differ from production and need a
 * coordinated DDL change on db2026 before this ships:
 *
 *  - `amount` is decimal(12,2), not decimal(8,2). Production's 8,2 caps
 *    at 999.999,99 while 28 active plans charge more than that (up to
 *    6.910.380 CLP), so those payments would throw on insert.
 *  - `external_reference` is new: the Webpay buy order (and, for
 *    reconciled transfers, the bank movement reference). Without it the
 *    only link back to the gateway is prose inside `notes`.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('suscriptor_payments', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('empresa_id');
            $table->integer('plan_id')->nullable();
            $table->string('periodo_plan', 50)->nullable();
            $table->string('notes')->nullable();
            $table->string('external_reference', 100)->nullable()->index();
            $table->unsignedBigInteger('factura_id')->nullable();
            $table->dateTime('fecha_pago')->nullable();
            $table->date('fecha_vencimiento_original')->nullable();
            $table->integer('responsable')->nullable();
            $table->string('comprobante_path', 500)->nullable();
            $table->string('comprobante_tipo', 10)->nullable();
            $table->string('comprobante_nombre_original')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('suscriptor_payments');
    }
};
