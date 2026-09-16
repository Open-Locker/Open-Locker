<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LockerBankConnectionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly array $recipientUserIds,
        public readonly string $lockerBankUuid,
        public readonly string $connectionStatus,
        public readonly ?string $connectionStatusChangedAtIso = null,
        public readonly ?string $lastHeartbeatAtIso = null,
    ) {}

    /**
     * Its own channel, named for what it carries. Served by the app's single
     * Echo instance, so this is one more subscription rather than one more
     * websocket.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel("users.{$userId}.locker-banks"),
            $this->recipientUserIds
        );
    }

    public function broadcastAs(): string
    {
        return 'locker_bank.connection.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'locker_bank_id' => $this->lockerBankUuid,
            'connection_status' => $this->connectionStatus,
            'connection_status_changed_at' => $this->connectionStatusChangedAtIso,
            'last_heartbeat_at' => $this->lastHeartbeatAtIso,
        ];
    }
}
