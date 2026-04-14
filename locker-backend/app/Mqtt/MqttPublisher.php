<?php

declare(strict_types=1);

namespace App\Mqtt;

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
        MQTT::connection('publisher')->publish(
            $topic,
            $this->payloadFactory->encode($payload),
            $qos,
            $retain,
        );
    }
}
