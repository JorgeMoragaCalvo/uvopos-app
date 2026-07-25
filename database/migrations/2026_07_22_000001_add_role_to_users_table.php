<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the role flag that gates the staff page at /payment-alert.
 * Null (the default) means a plain company user, who only ever sees
 * /mi-cuenta; 'admin' unlocks the all-customers list and the
 * suspend/reactivate actions.
 *
 * This is the local-dev mirror: the production `db2026.users` table
 * needs the same nullable column added before this ships.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('status');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
