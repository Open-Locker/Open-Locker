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
            $table->unsignedInteger('heartbeat_interval_seconds')
                ->default(10)
                ->after('last_heartbeat_at');

            $table->unsignedInteger('heartbeat_timeout_seconds')
                ->default(30)
                ->after('heartbeat_interval_seconds');

            $table->string('connection_status', 16)
                ->default('unknown')
                ->after('heartbeat_timeout_seconds');

            $table->dateTime('connection_status_changed_at')
                ->nullable()
                ->after('connection_status');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dropColumn([
                'heartbeat_interval_seconds',
                'heartbeat_timeout_seconds',
                'connection_status',
                'connection_status_changed_at',
            ]);
        });
    }
};
