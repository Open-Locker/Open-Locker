<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompartmentDoorStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly array $recipientUserIds,
        public readonly string $compartmentUuid,
        public readonly string $doorState,
        public readonly ?string $doorStateChangedAtIso = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel("users.{$userId}.compartment-status"),
            $this->recipientUserIds
        );
    }

    public function broadcastAs(): string
    {
        return 'compartment.door_state.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'compartment_id' => $this->compartmentUuid,
            'door_state' => $this->doorState,
            'door_state_changed_at' => $this->doorStateChangedAtIso,
        ];
    }
}
