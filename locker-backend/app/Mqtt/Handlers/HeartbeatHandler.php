<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Models\LockerBank;
use App\StorableEvents\LockerConnectionRestored;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HeartbeatHandler
{
    /**
     * Handle incoming heartbeat on topic pattern 'locker/{uuid}/state'.
     *
     * @param  array<string,mixed>  $payload
     */
    public function handle(string $topic, array $payload): void
    {
        $lockerBankUuid = Str::after($topic, 'locker/');
        $lockerBankUuid = Str::before($lockerBankUuid, '/state');

        $timestamp = isset($payload['data']['timestamp']) && is_string($payload['data']['timestamp'])
            ? $payload['data']['timestamp']
            : null;

        $lockerBank = LockerBank::find($lockerBankUuid);
        if (! $lockerBank) {
            Log::warning('Heartbeat received for unknown locker bank', [
                'uuid' => $lockerBankUuid,
                'timestamp' => $timestamp,
            ]);

            return;
        }

        $ts = $timestamp ? Carbon::parse($timestamp) : now();
        $wasOffline = $lockerBank->connection_status === 'offline';

        $lockerBank->forceFill([
            'last_heartbeat_at' => $ts,
            'connection_status' => 'online',
            'connection_status_changed_at' => $wasOffline ? $ts : $lockerBank->connection_status_changed_at,
        ])->save();

        Log::info('Heartbeat received', [
            'uuid' => $lockerBankUuid,
            'timestamp' => $timestamp,
        ]);

        if ($wasOffline) {
            event(new LockerConnectionRestored(
                lockerBankUuid: $lockerBankUuid,
                restoredAtIso8601: $ts->toIso8601String(),
                reason: 'heartbeat',
            ));
        }
    }
}
