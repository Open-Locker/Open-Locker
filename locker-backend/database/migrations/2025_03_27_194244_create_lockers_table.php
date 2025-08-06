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
            $table->uuid('id')->primary();
            $table->timestamps();
            $table->string('name');
            $table->unsignedInteger('unit_id');
            $table->unsignedInteger('coil_address');
            $table->unsignedInteger('input_address');
            $table->string('status')->default('unknown');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignUuid('locker_id')->nullable()->constrained('lockers');
        });
    }
};
