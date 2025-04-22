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
        Schema::create('lockers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            $table->unsignedInteger('unit_id');
            $table->unsignedInteger('coil_address');
            $table->unsignedInteger('input_address');

        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('locker_id')->nullable()->constrained('lockers');
        });
    }
};
