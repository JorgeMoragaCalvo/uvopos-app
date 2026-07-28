<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Webpay Plus checkout we start, written before the browser
 * leaves for Transbank. Like `bank_statements` / `bank_movements`, this
 * table is owned by this module and does not exist in db2026 yet.
 *
 * It exists because the return leg cannot rely on the session. Transbank
 * sends the customer back with a cross-site POST, and a SameSite=Lax
 * cookie is not sent on one — so the pending payload has to be looked up
 * by something that travels in the request body instead. `buy_order` is
 * that key, which also makes it the natural idempotency guard: it is
 * unique, and a replayed return finds the row already out of `pending`.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('webpay_transactions', function (Blueprint $table) {
            $table->id();

            // Sent to Transbank at create() and echoed back on every
            // return, including the aborted ones that carry no token.
            $table->string('buy_order', 26)->unique();
            $table->string('session_id', 61);

            $table->unsignedBigInteger('empresa_id')->index();

            // Who started the payment: staff on /payment-alert, or the
            // customer themselves on /mi-cuenta. Carried here because
            // suscriptor_payments.user_id is NOT NULL in production and
            // the Transbank return lands unauthenticated.
            $table->unsignedBigInteger('user_id');

            // What we asked Transbank to charge, checked against what the
            // commit says was actually charged.
            $table->decimal('amount', 12, 2);

            // Enough to rebuild the page the payment started from.
            $table->string('search', 100)->default('');
            $table->string('return_to', 20)->default('payment-alert');

            // pending -> authorized | declined | aborted | failed
            $table->string('status', 20)->default('pending')->index();

            $table->string('token', 100)->nullable();
            $table->unsignedBigInteger('suscriptor_payment_id')->nullable();
            $table->smallInteger('response_code')->nullable();
            $table->dateTime('committed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('webpay_transactions');
    }
};
