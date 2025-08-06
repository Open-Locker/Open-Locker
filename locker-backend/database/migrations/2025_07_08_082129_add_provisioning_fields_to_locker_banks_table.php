<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->string('provisioning_token', 64)->unique()->nullable()->after('id');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dropColumn(['provisioning_token', 'provisioned_at']);
        });
    }
};
