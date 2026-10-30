<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            // Why the backend itself ended the update (rollback, timeout), apart from the device's
            // own code in firmware_update_error_code (ADR-0077).
            $table->string('firmware_update_failure_reason', 16)->nullable()->after('firmware_update_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropColumn('firmware_update_failure_reason');
        });
    }
};
