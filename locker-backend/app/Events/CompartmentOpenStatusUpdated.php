<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompartmentOpenStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $commandId,
        public readonly string $compartmentUuid,
        public readonly string $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}.compartment-open")];
    }

    public function broadcastAs(): string
    {
        return 'compartment.open.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->commandId,
            'compartment_id' => $this->compartmentUuid,
            'status' => $this->status,
            'error_code' => $this->errorCode,
            'message' => $this->message,
        ];
    }
}
