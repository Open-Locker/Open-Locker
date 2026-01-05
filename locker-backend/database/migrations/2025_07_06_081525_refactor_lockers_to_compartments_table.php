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
        Schema::rename('lockers', 'compartments');

        Schema::table('compartments', function (Blueprint $table) {
            // Add new columns for the new architecture
            $table->foreignUuid('locker_bank_id')->after('id')->constrained('locker_banks')->onDelete('cascade');
            $table->string('label')->after('name')->comment('The human-readable label for the compartment, e.g., A01');

            // Drop old Modbus-specific columns
            $table->dropColumn(['unit_id', 'coil_address', 'input_address', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locker_bank_id');
            $table->dropColumn(['label']);

            // Restore old Modbus-specific columns
            $table->unsignedInteger('unit_id')->default(1);
            $table->unsignedInteger('coil_address');
            $table->unsignedInteger('input_address');
            $table->string('status')->default('unknown');
        });

        Schema::rename('compartments', 'lockers');
    }
};
