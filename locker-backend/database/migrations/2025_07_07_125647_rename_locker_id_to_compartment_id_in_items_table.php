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
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['locker_id']);
            $table->renameColumn('locker_id', 'compartment_id');
            $table->foreign('compartment_id')->references('id')->on('compartments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['compartment_id']);
            $table->renameColumn('compartment_id', 'locker_id');
            $table->foreign('locker_id')->references('id')->on('lockers');
        });
    }
};
