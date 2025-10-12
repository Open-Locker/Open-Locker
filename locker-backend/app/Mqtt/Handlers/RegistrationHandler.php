<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Aggregates\LockerBankAggregate;
use App\Models\LockerBank;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpMqtt\Client\Facades\MQTT;

class RegistrationHandler
{
    /**
     * Handle incoming registration message on topic pattern 'locker/register/+'.
     *
     * @param  string  $topic  The full topic the message was received on.
     * @param  array<string,mixed>  $payload  Decoded JSON payload.
     */
    public function handle(string $topic, array $payload): void
    {
        $provisioningToken = Str::after($topic, 'locker/register/');

        // Validate payload
        $clientId = Arr::get($payload, 'client_id');
        if (! is_string($clientId) || $clientId === '') {
            Log::warning('Invalid registration payload received', [
                'topic' => $topic,
                'payload' => $payload,
            ]);

            return;
        }

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

            // Fallback: publish credentials directly if reactor is unavailable
            $mqttUser = $lockerBank->id;
            $mqttPassword = Str::random(32);
            $payload = json_encode([
                'status' => 'success',
                'data' => [
                    'mqtt_user' => $mqttUser,
                    'mqtt_password' => $mqttPassword,
                ],
            ]);

            // Note: backend publish is allowed by ACL (locker/#)
            MQTT::connection('publisher')->publish($replyToTopic, (string) $payload, 1);
            Log::info('Direct provisioning reply published');
        }
    }
}
