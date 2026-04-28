<?php

declare(strict_types=1);

namespace App\Mqtt\Publishers;

use App\Mqtt\MqttPublisher;
use App\StorableEvents\CompartmentOpeningRequested;
use Illuminate\Support\Facades\Log;

class OpenCompartmentCommandPublisher
{
    public function __construct(
        private readonly MqttPublisher $mqttPublisher,
    ) {}

    public function publish(CompartmentOpeningRequested $event): void
    {
        $topic = "locker/{$event->lockerBankUuid}/command";

        Log::info('[OpenCompartmentCommandPublisher] Publishing open_compartment command.', [
            'topic' => $topic,
            'lockerBankUuid' => $event->lockerBankUuid,
            'compartmentUuid' => $event->compartmentUuid,
            'compartmentNumber' => $event->compartmentNumber,
            'transactionId' => $event->commandId,
        ]);

        $this->mqttPublisher->publish($topic, [
            'action' => 'open_compartment',
            'transaction_id' => $event->commandId,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'compartment_number' => $event->compartmentNumber,
            ],
        ]);
    }
}
