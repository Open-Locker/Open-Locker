<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Aggregates\LockerBankAggregate;
use App\Models\LockerBank;
use App\Mqtt\InboundMqttProtocolGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RegistrationHandler extends AbstractInboundMqttHandler
{
    public function __construct(InboundMqttProtocolGuard $guard)
    {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/register/+';
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT registration message received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'client_id' => ['required', 'string'],
        ];
    }

    /**
     * Handle incoming registration message on topic pattern 'locker/register/+'.
     *
     * @param  string  $topic  The full topic the message was received on.
     * @param  array<string,mixed>  $payload  Decoded JSON payload.
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $provisioningToken = Str::after($topic, 'locker/register/');
        $clientId = (string) $payload['client_id'];

        $replyToTopic = 'locker/provisioning/reply/'.$clientId;

        Log::info('Looking up LockerBank by provisioning token', [
            'token' => $provisioningToken,
        ]);

        $lockerBank = LockerBank::where('provisioning_token', $provisioningToken)->first();

        if (! $lockerBank) {
            Log::warning('No LockerBank found for provisioning token', [
                'token' => $provisioningToken,
            ]);

            return;
        }

        Log::info('Provisioning LockerBank', [
            'uuid' => $lockerBank->id,
            'replyTo' => $replyToTopic,
        ]);

        try {
            LockerBankAggregate::retrieve($lockerBank->id)
                ->provision($lockerBank, $replyToTopic)
                ->persist();

            Log::info('Provisioning event emitted');
        } catch (\Throwable $e) {
            Log::error('Failed to emit provisioning event, falling back to direct reply', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
