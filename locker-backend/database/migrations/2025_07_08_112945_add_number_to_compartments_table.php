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
        Schema::table('compartments', function (Blueprint $table) {
            $table->unsignedInteger('number')->after('label');
            $table->unique(['locker_bank_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->dropUnique(['locker_bank_id', 'number']);
            $table->dropColumn('number');
        });
    }
};
