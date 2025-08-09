<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dateTime('last_heartbeat_at')->nullable()->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });
    }
};
