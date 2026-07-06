<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompartmentNoteUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly array $recipientUserIds,
        public readonly string $compartmentUuid,
        public readonly ?string $note,
        public readonly string $noteUpdatedAtIso,
        public readonly int $noteUpdatedByUserId,
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
        return 'compartment.content_note.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'compartment_id' => $this->compartmentUuid,
            'content_note' => $this->note,
            'content_note_updated_at' => $this->noteUpdatedAtIso,
            'content_note_updated_by_user_id' => $this->noteUpdatedByUserId,
        ];
    }
}
