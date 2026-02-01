<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CommandTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommandResponseInboxService
{
    /**
     * Record an incoming command response if this (locker_uuid, transaction_id) pair
     * has not been seen before.
     *
     * Returns true if this is the FIRST time we see the response (caller may emit domain events).
     * Returns false if this is a duplicate (caller should ignore side effects).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordIfFirst(string $lockerUuid, string $transactionId, string $topic, array $payload): bool
    {
        $now = Carbon::now();
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : null;
        $result = isset($payload['result']) && is_string($payload['result']) ? $payload['result'] : null;
        $errorCode = isset($payload['error_code']) && is_string($payload['error_code']) ? $payload['error_code'] : null;

        // Avoid throwing a unique constraint exception (important when tests wrap each case
        // in a database transaction, e.g. Postgres would mark it as aborted).
        $inserted = DB::table('command_transactions')->insertOrIgnore([
            'locker_uuid' => $lockerUuid,
            'transaction_id' => $transactionId,
            'action' => $action,
            'result' => $result,
            'error_code' => $errorCode,
            'source_topic' => $topic,
            'payload_hash' => $payloadHash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return true;
        }

        Log::info('Duplicate command response received (deduped).', [
            'locker_uuid' => $lockerUuid,
            'transaction_id' => $transactionId,
            'topic' => $topic,
        ]);

        CommandTransaction::query()
            ->where('locker_uuid', $lockerUuid)
            ->where('transaction_id', $transactionId)
            ->update([
                'last_seen_at' => $now,
            ]);

        return false;
    }
}
