<?php

use App\Enums\LockerStatus;
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
            $table->enum('status', array_map(fn (LockerStatus $status) => $status->value, LockerStatus::cases()))->default(LockerStatus::Unknown->value);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('locker_id')->nullable()->constrained('lockers');
        });
    }
};
