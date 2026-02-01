<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create a durable inbox/tracker table for MQTT command responses.
     *
     * This table is used for deduplication because MQTT QoS 1 is "at least once".
     */
    public function up(): void
    {
        Schema::create('command_transactions', function (Blueprint $table) {
            $table->id();

            $table->string('locker_uuid');
            $table->string('transaction_id');

            $table->string('action')->nullable();
            $table->string('result')->nullable(); // success|error|timeout (etc.)
            $table->string('error_code')->nullable();

            $table->string('source_topic')->nullable();
            $table->string('payload_hash')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['locker_uuid', 'transaction_id'], 'command_txn_locker_transaction_unique');
            $table->index(['locker_uuid', 'created_at'], 'command_txn_locker_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_transactions');
    }
};
