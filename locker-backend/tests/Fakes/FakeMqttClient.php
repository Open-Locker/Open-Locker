<?php

declare(strict_types=1);

namespace Tests\Fakes;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\MqttClient;

class FakeMqttClient implements MqttClient
{
    /** @var array<int, array{topic: string, payload: string, qos: int, retain: bool}> */
    public array $published = [];

    public function connect(?ConnectionSettings $settings = null, bool $useCleanSession = false): void {}

    public function disconnect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $this->published[] = [
            'topic' => $topic,
            'payload' => $message,
            'qos' => $qualityOfService,
            'retain' => $retain,
        ];
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = 0): void {}

    public function unsubscribe(string $topicFilter): void {}

    public function interrupt(): void {}

    public function loop(bool $allowSleep = true, bool $exitWhenQueuesEmpty = false, ?int $queueWaitLimit = null): void {}

    public function loopOnce(float $loopStartedAt, bool $allowSleep = false, int $sleepMicroseconds = 100000): void {}

    public function getHost(): string
    {
        return 'localhost';
    }

    public function getPort(): int
    {
        return 1883;
    }

    public function getClientId(): string
    {
        return 'fake-client';
    }

    public function getReceivedBytes(): int
    {
        return 0;
    }

    public function getSentBytes(): int
    {
        return 0;
    }

    public function registerLoopEventHandler(\Closure $callback): MqttClient
    {
        return $this;
    }

    public function unregisterLoopEventHandler(?\Closure $callback = null): MqttClient
    {
        return $this;
    }

    public function registerPublishEventHandler(\Closure $callback): MqttClient
    {
        return $this;
    }

    public function unregisterPublishEventHandler(?\Closure $callback = null): MqttClient
    {
        return $this;
    }

    public function registerMessageReceivedEventHandler(\Closure $callback): MqttClient
    {
        return $this;
    }

    public function unregisterMessageReceivedEventHandler(?\Closure $callback = null): MqttClient
    {
        return $this;
    }
}
