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
            $table->dateTime('last_config_sent_at')->nullable()->after('last_heartbeat_at');
            $table->string('last_config_sent_hash', 64)->nullable()->after('last_config_sent_at');

            $table->dateTime('last_config_ack_at')->nullable()->after('last_config_sent_hash');
            $table->string('last_config_ack_hash', 64)->nullable()->after('last_config_ack_at');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dropColumn([
                'last_config_sent_at',
                'last_config_sent_hash',
                'last_config_ack_at',
                'last_config_ack_hash',
            ]);
        });
    }
};
