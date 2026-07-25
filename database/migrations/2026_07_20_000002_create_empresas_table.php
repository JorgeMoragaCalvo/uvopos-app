<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of the production `empresas` table — only the columns
 * this app's Payment Alert / Webpay / suspend feature touches, not full
 * production parity.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('rut');
            $table->string('RazonSocial')->nullable();
            $table->string('nombre_fantasia', 250)->nullable();
            $table->date('proximoPago')->nullable();
            $table->string('tipoPlan', 45)->nullable();
            $table->string('estado', 45)->default('1');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('empresas');
    }
};
