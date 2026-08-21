<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Mqtt\InboundMqttProtocolGuard;
use App\Mqtt\MqttTopicRedactor;
use App\Observability\MqttSpanAttributes;
use App\Observability\MqttTraceContext;
use App\Observability\SpanFlusher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;

abstract class AbstractInboundMqttHandler
{
    public function __construct(protected readonly InboundMqttProtocolGuard $guard) {}

    abstract public function topicPattern(): string;

    public function handleMessage(string $topic, string $message): void
    {
        if (! $this->tracesInboundMessages()) {
            $this->processMessage($topic, $message);

            return;
        }

        // Decoded here only to derive span attributes and the remote parent;
        // processMessage() keeps ownership of decoding, validation, and the
        // invalid-JSON path.
        $decoded = json_decode($message, true);
        $payload = is_array($decoded) ? $decoded : [];

        try {
            Tracer::newSpan(sprintf('mqtt process %s', MqttSpanAttributes::destination($topic)))
                // Null when the sender did not send trace context, which starts a
                // fresh trace rather than rejecting the message.
                ->setParent(MqttTraceContext::extract($payload))
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setAttributes(MqttSpanAttributes::fromMessage($topic, $payload))
                ->measure(function () use ($topic, $message): void {
                    // Puts trace_id into the log context of everything this
                    // handler logs, including the reactors it triggers.
                    Tracer::updateLogContext();

                    $this->processMessage($topic, $message);
                });
        } finally {
            // Shared log context outlives the span, and this listener outlives
            // everything. Without clearing it, every later line — including the
            // heartbeats deliberately left untraced — would be stamped with the
            // trace id of whichever message happened to be handled last.
            Log::withoutContext();

            SpanFlusher::flush();
        }
    }

    /**
     * Periodic chatter carries no useful timing and would bury the flows worth
     * tracing, so heartbeats and compartment snapshots opt out.
     */
    protected function tracesInboundMessages(): bool
    {
        return true;
    }

    private function processMessage(string $topic, string $message): void
    {
        Log::info($this->receivedLogMessage(), [
            'topic' => MqttTopicRedactor::redact($topic),
            'message' => $message,
        ]);

        $payload = json_decode($message, true) ?? [];
        if (! is_array($payload)) {
            Log::warning('Invalid JSON payload received', [
                'topic' => MqttTopicRedactor::redact($topic),
                'raw' => $message,
            ]);

            return;
        }

        if (! $this->guard->allow($topic, $payload, $this->requiresTransactionId(), $this->blocksInboundDuplicateMessageIds())) {
            return;
        }

        $validator = $this->makeValidator($payload);
        if ($validator->fails()) {
            Log::warning('Rejected inbound MQTT payload due to validation errors.', [
                'topic' => MqttTopicRedactor::redact($topic),
                'payload' => $payload,
                'errors' => $validator->errors()->toArray(),
                'handler' => static::class,
            ]);

            return;
        }

        $this->handleValidated($topic, $payload);
    }

    /**
     * When false, {@see InboundMqttProtocolGuard} still requires message_id but does not suppress
     * duplicate IDs (used for idempotent retained compartment snapshots).
     */
    protected function blocksInboundDuplicateMessageIds(): bool
    {
        return true;
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
     * @param  array<string, mixed>  $payload
     */
    protected function makeValidator(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($payload, $this->rules(), $this->messages(), $this->attributes());
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
