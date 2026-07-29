<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the movements the importer reconciled on its own.
 *
 * `reconciled_by` is null for those rows, but it is also null for a
 * Transbank payout tag, so it can't tell the two apart. The distinction
 * matters in the queue: staff need to see which payments nobody reviewed.
 *
 * `bank_movements` is owned by this module, so this needs no DDL
 * coordination on db2026.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('bank_movements', function (Blueprint $table) {
            $table->boolean('auto_confirmed')->default(false)->after('reconciled_at');
        });
    }

    public function down()
    {
        Schema::table('bank_movements', function (Blueprint $table) {
            $table->dropColumn('auto_confirmed');
        });
    }
};
