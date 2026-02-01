<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->unsignedSmallInteger('slave_id')->nullable()->after('number');
            $table->unsignedInteger('address')->nullable()->after('slave_id');

            $table->unique(['locker_bank_id', 'slave_id', 'address'], 'compartments_bank_slave_address_unique');
        });
    }

    public function down(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->dropUnique('compartments_bank_slave_address_unique');
            $table->dropColumn(['slave_id', 'address']);
        });
    }
};
