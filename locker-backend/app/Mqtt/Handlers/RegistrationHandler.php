<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Mqtt\InboundMqttProtocolGuard;
use App\Mqtt\Publishers\ProvisioningReplyPublisher;
use App\Services\LockerProvisioningService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RegistrationHandler extends AbstractInboundMqttHandler
{
    public function __construct(
        InboundMqttProtocolGuard $guard,
        private readonly ProvisioningReplyPublisher $provisioningReplyPublisher,
        private readonly LockerProvisioningService $lockerProvisioningService,
    ) {
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
            'client_id' => ['required', 'string', 'regex:/\A(?!\s*\z)[^\/+#]+\z/u'],
            'timestamp' => ['required', 'string'],
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

        // The token is a credential and it is the whole topic suffix, so neither
        // it nor the topic can be logged — not even truncated, since a prefix
        // still narrows a brute-force search. The client id is enough to follow
        // a registration attempt through the log.
        Log::info('Looking up LockerBank by provisioning token', [
            'client_id' => $clientId,
        ]);

        try {
            $accepted = $this->lockerProvisioningService->acceptRegistration(
                $provisioningToken,
                $replyToTopic,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to accept locker provisioning registration.', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $accepted) {
            Log::warning('No LockerBank found for provisioning token', [
                'client_id' => $clientId,
            ]);

            $this->provisioningReplyPublisher->publishInvalidToken($replyToTopic);

            return;
        }

        Log::info('Provisioning event emitted', [
            'client_id' => $clientId,
        ]);
    }
}
