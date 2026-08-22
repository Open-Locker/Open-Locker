<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            // Nullable on purpose: null means the bank has not reported hardware
            // state yet, which is not the same as reporting it as unreachable.
            $table->boolean('modbus_connected')
                ->nullable()
                ->after('connection_status_changed_at');

            $table->dateTime('modbus_status_reported_at')
                ->nullable()
                ->after('modbus_connected');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dropColumn(['modbus_connected', 'modbus_status_reported_at']);
        });
    }
};
