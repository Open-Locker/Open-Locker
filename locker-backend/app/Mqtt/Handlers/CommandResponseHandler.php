<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Mqtt\InboundMqttProtocolGuard;
use App\Services\CommandResponseInboxService;
use App\StorableEvents\CommandResponseReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommandResponseHandler extends AbstractInboundMqttHandler
{
    public function __construct(
        private readonly CommandResponseInboxService $inbox,
        InboundMqttProtocolGuard $guard,
    ) {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/+/response';
    }

    protected function requiresTransactionId(): bool
    {
        return true;
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT response message received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'action' => ['required', 'string'],
            'result' => ['required', 'string', 'in:success,error'],
            'timestamp' => ['required', 'string'],
            'error_code' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
            'applied_config_hash' => ['nullable', 'string'],
        ];
    }

    /**
     * Handle incoming command response on topic pattern 'locker/{uuid}/response'
     *.
     *
     * @param  array<string,mixed>  $payload
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $lockerBankUuid = Str::after($topic, 'locker/');
        $lockerBankUuid = Str::before($lockerBankUuid, '/');
        $transactionId = trim((string) $payload['transaction_id']);

        $isFirst = $this->inbox->recordIfFirst($lockerBankUuid, $transactionId, $topic, $payload);
        if (! $isFirst) {
            return; // dedup: do not create duplicate side effects
        }

        $type = (string) $payload['type'];
        $action = (string) $payload['action'];
        $result = (string) $payload['result'];
        $timestamp = (string) $payload['timestamp'];
        $errorCode = isset($payload['error_code']) && is_string($payload['error_code']) ? $payload['error_code'] : null;
        $message = isset($payload['message']) && is_string($payload['message']) ? $payload['message'] : null;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        // Promote top-level device-only fields into data so downstream event sourcing
        // can derive domain-specific events without depending on the raw MQTT payload.
        if (isset($payload['applied_config_hash']) && is_string($payload['applied_config_hash']) && ! array_key_exists('applied_config_hash', $data)) {
            $data['applied_config_hash'] = $payload['applied_config_hash'];
        }

        if ($type !== 'command_response') {
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
