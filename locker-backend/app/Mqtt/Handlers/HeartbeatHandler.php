<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\StorableEvents\HeartbeatReceived;
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

        Log::info('Heartbeat received', [
            'uuid' => $lockerBankUuid,
            'timestamp' => $timestamp,
        ]);

        event(new HeartbeatReceived(
            lockerBankUuid: $lockerBankUuid,
            timestamp: $timestamp,
            data: $payload['data'] ?? [],
        ));
    }
}
