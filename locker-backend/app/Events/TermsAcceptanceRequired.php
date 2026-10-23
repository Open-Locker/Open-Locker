<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells connected apps that the terms they are holding are no longer the current
 * ones, so the profile they cached is stale.
 *
 * Carries the version and nothing else. The app re-reads its own profile to learn
 * what it now needs to do; sending terms content here would put a second copy of
 * the answer on the wire, free to disagree with the first.
 */
class TermsAcceptanceRequired implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly array $recipientUserIds,
        public readonly int $version,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel("users.{$userId}.account"),
            $this->recipientUserIds
        );
    }

    public function broadcastAs(): string
    {
        return 'terms.acceptance-required';
    }

    /**
     * A signal, not a copy of the terms: the app re-reads its own profile.
     *
     * Deliberately carries no organization. The channel is keyed by user, so
     * someone in two of them receives both — but the re-read goes out with
     * their active organization, so the answer that comes back is already the
     * right one and there is nothing here to filter on.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['version' => $this->version];
    }
}
