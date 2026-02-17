<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\CompartmentAccess;
use App\StorableEvents\CompartmentAccessGranted;
use App\StorableEvents\CompartmentAccessRevoked;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class CompartmentAccessProjector extends Projector implements ShouldQueue
{
    public function onCompartmentAccessGranted(CompartmentAccessGranted $event): void
    {
        $grantedAt = Carbon::parse($event->grantedAt);
        $expiresAt = $event->expiresAt ? Carbon::parse($event->expiresAt) : null;

        CompartmentAccess::query()->updateOrCreate(
            [
                'user_id' => $event->userId,
                'compartment_id' => $event->compartmentUuid,
            ],
            [
                'granted_at' => $grantedAt,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'notes' => $event->notes,
            ]
        );
    }

    public function onCompartmentAccessRevoked(CompartmentAccessRevoked $event): void
    {
        CompartmentAccess::query()
            ->where('user_id', $event->userId)
            ->where('compartment_id', $event->compartmentUuid)
            ->update([
                'revoked_at' => Carbon::parse($event->revokedAt),
            ]);
    }
}
