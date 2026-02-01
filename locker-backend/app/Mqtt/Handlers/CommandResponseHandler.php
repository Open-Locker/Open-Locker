<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Services\CommandResponseInboxService;
use App\StorableEvents\CommandResponseReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommandResponseHandler
{
    public function __construct(private readonly CommandResponseInboxService $inbox) {}

    /**
     * Handle incoming command response on topic pattern 'locker/{uuid}/response'
     *.
     *
     * @param  array<string,mixed>  $payload
     */
    public function handle(string $topic, array $payload): void
    {
        $lockerBankUuid = Str::after($topic, 'locker/');
        $lockerBankUuid = Str::before($lockerBankUuid, '/');

        $transactionId = isset($payload['transaction_id']) && is_string($payload['transaction_id'])
            ? trim($payload['transaction_id'])
            : '';

        if ($transactionId === '') {
            Log::warning('Command response missing transaction_id; ignoring.', [
                'topic' => $topic,
                'payload' => $payload,
            ]);

            return;
        }

        $isFirst = $this->inbox->recordIfFirst($lockerBankUuid, $transactionId, $topic, $payload);
        if (! $isFirst) {
            return; // dedup: do not create duplicate side effects
        }

        $type = isset($payload['type']) && is_string($payload['type']) ? $payload['type'] : null;
        $action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : null;
        $result = isset($payload['result']) && is_string($payload['result']) ? $payload['result'] : null;
        $timestamp = isset($payload['timestamp']) && is_string($payload['timestamp']) ? $payload['timestamp'] : null;
        $errorCode = isset($payload['error_code']) && is_string($payload['error_code']) ? $payload['error_code'] : null;
        $message = isset($payload['message']) && is_string($payload['message']) ? $payload['message'] : null;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        // Promote top-level device-only fields into data so downstream event sourcing
        // can derive domain-specific events without depending on the raw MQTT payload.
        if (isset($payload['applied_config_hash']) && is_string($payload['applied_config_hash']) && ! array_key_exists('applied_config_hash', $data)) {
            $data['applied_config_hash'] = $payload['applied_config_hash'];
        }

        if ($type !== null && $type !== 'command_response') {
            Log::warning('Unexpected response type received; continuing anyway.', [
                'topic' => $topic,
                'type' => $type,
            ]);
        }

        event(new CommandResponseReceived(
            lockerBankUuid: $lockerBankUuid,
            transactionId: $transactionId,
            action: $action,
            result: $result,
            timestamp: $timestamp,
            errorCode: $errorCode,
            message: $message,
            data: $data,
        ));
    }
}
