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
            $table->dropColumn(['name', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->string('label')->after('name');
        });
    }
};
