<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded bank statement (cartola).
 *
 * Unlike `empresas` / `datos_plan` / `suscriptor_payments`, this table is
 * NOT a mirror of something that already exists in db2026 — it is owned
 * by this module, so this migration is real DDL that has to be applied to
 * production in a coordinated change with the db2026 owner.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 50);
            $table->string('account_number', 50)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('original_filename');
            $table->string('stored_path', 500);
            // SHA-256 of the uploaded bytes: re-uploading the same export
            // is the most likely staff mistake, and it would otherwise
            // duplicate every movement in it.
            $table->string('file_hash', 64)->unique();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->integer('movement_count')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_statements');
    }
};
