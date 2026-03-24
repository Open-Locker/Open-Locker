<?php

declare(strict_types=1);

namespace App\Mqtt;

use Illuminate\Support\Str;

class MqttPayloadFactory
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withMessageId(array $payload): array
    {
        if (! array_key_exists('message_id', $payload)) {
            $payload['message_id'] = Str::uuid()->toString();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return (string) json_encode($this->withMessageId($payload));
    }
}
