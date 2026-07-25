<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of the production `datos_plan` table — holds each
 * company's current plan pricing/duration, used to price the Webpay
 * charge and to extend the due date on a successful payment.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('datos_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->integer('plan_id')->nullable();
            $table->integer('monto_plan')->nullable();
            $table->integer('monto_hardware')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('periodo_plan', 45)->nullable();
            $table->integer('periodo_days')->default(30);
            $table->integer('estado')->default(1);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('datos_plan');
    }
};
