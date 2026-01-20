<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Models\LockerBank;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommandResponseHandler
{
    /**
     * Handle incoming responses on topic pattern 'locker/{uuid}/response'.
     *
     * @param  array<string,mixed>  $payload
     */
    public function handle(string $topic, array $payload): void
    {
        $lockerBankUuid = Str::after($topic, 'locker/');
        $lockerBankUuid = Str::before($lockerBankUuid, '/response');

        $type = $payload['type'] ?? null;
        $action = $payload['action'] ?? null;
        $result = $payload['result'] ?? null;

        if ($type !== 'command_response' || ! is_string($action) || ! is_string($result)) {
            Log::warning('Invalid command response payload received', [
                'topic' => $topic,
                'payload' => $payload,
            ]);

            return;
        }

        if ($action !== 'apply_config') {
            // Out of scope for now (we can expand later).
            return;
        }

        if ($result !== 'success') {
            Log::warning('apply_config returned non-success result', [
                'lockerBankUuid' => $lockerBankUuid,
                'payload' => $payload,
            ]);

            return;
        }

        $appliedHash = $payload['applied_config_hash'] ?? null;
        if (! is_string($appliedHash) || strlen($appliedHash) !== 64) {
            Log::warning('apply_config success missing valid applied_config_hash', [
                'lockerBankUuid' => $lockerBankUuid,
                'payload' => $payload,
            ]);

            return;
        }

        $lockerBank = LockerBank::find($lockerBankUuid);
        if (! $lockerBank) {
            Log::warning('LockerBank not found for command response', [
                'lockerBankUuid' => $lockerBankUuid,
                'topic' => $topic,
            ]);

            return;
        }

        $lockerBank->update([
            'last_config_ack_at' => now(),
            'last_config_ack_hash' => $appliedHash,
        ]);

        Log::info('apply_config acknowledged by client', [
            'lockerBankUuid' => $lockerBankUuid,
            'appliedConfigHash' => $appliedHash,
        ]);
    }
}
