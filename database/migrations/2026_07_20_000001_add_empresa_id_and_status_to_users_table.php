<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the empresa_id/status columns that already exist on the
 * production `users` table, so local dev can link staff/company users
 * and suspend/reactivate them the same way production does.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->after('password');
            $table->tinyInteger('status')->default(1)->after('empresa_id');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['empresa_id', 'status']);
        });
    }
};
