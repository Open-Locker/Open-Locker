<?php

declare(strict_types=1);

namespace App\Mqtt;

use App\Observability\MqttSpanAttributes;
use App\Observability\MqttTraceContext;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use PhpMqtt\Client\Facades\MQTT;

class MqttPublisher
{
    public function __construct(
        private readonly MqttPayloadFactory $payloadFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(
        string $topic,
        array $payload,
        int $qos = 1,
        bool $retain = false,
    ): void {
        // Resolve the message id up front so the span can record it; encode()
        // leaves an existing id untouched.
        $payload = $this->payloadFactory->withMessageId($payload);

        Tracer::newSpan(sprintf('mqtt publish %s', MqttSpanAttributes::destination($topic)))
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttributes(MqttSpanAttributes::fromMessage($topic, $payload))
            ->measure(function () use ($topic, $payload, $qos, $retain): void {
                // Stamped inside the span so the receiving end continues from
                // this publish, not from whatever started the flow.
                $payload = MqttTraceContext::inject($payload);

                MQTT::connection('publisher')->publish(
                    $topic,
                    $this->payloadFactory->encode($payload),
                    $qos,
                    $retain,
                );
            });
    }
}
