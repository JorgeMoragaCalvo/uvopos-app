<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single line of a bank statement, plus the reconciliation decision
 * made about it. Like `bank_statements`, this table is owned by this
 * module and does not exist in db2026 yet.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('bank_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_statement_id')->index();
            $table->date('posted_at')->index();
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('direction', 10);          // 'credit' | 'debit'
            $table->string('counterparty_rut', 20)->nullable()->index();

            // Fingerprint of the parsed line (date + amount + glosa + ref).
            // Cartola exports routinely overlap on dates, so the same
            // deposit arrives in two different files; this is what stops
            // it being reconciled twice. Unique rather than indexed: the
            // importer's own check is a read, so two simultaneous imports
            // would both pass it — the constraint is the real guard.
            $table->string('row_hash', 64)->unique();

            $table->string('status', 20)->default('unmatched')->index();
            $table->unsignedBigInteger('empresa_id')->nullable()->index();
            $table->unsignedBigInteger('suscriptor_payment_id')->nullable();
            $table->tinyInteger('match_confidence')->nullable();
            $table->string('match_reason', 255)->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_movements');
    }
};
