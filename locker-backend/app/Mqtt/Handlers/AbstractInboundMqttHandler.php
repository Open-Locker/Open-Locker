<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Mqtt\InboundMqttProtocolGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

abstract class AbstractInboundMqttHandler
{
    public function __construct(protected readonly InboundMqttProtocolGuard $guard) {}

    abstract public function topicPattern(): string;

    public function handleMessage(string $topic, string $message): void
    {
        Log::info($this->receivedLogMessage(), [
            'topic' => $topic,
            'message' => $message,
        ]);

        $payload = json_decode($message, true) ?? [];
        if (! is_array($payload)) {
            Log::warning('Invalid JSON payload received', [
                'topic' => $topic,
                'raw' => $message,
            ]);

            return;
        }

        if (! $this->guard->allow($topic, $payload, $this->requiresTransactionId())) {
            return;
        }

        $validator = Validator::make($payload, $this->rules(), $this->messages(), $this->attributes());
        if ($validator->fails()) {
            Log::warning('Rejected inbound MQTT payload due to validation errors.', [
                'topic' => $topic,
                'payload' => $payload,
                'errors' => $validator->errors()->toArray(),
                'handler' => static::class,
            ]);

            return;
        }

        $this->handleValidated($topic, $payload);
    }

    protected function requiresTransactionId(): bool
    {
        return false;
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT message received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    abstract protected function handleValidated(string $topic, array $payload): void;
}
