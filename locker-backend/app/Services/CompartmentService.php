<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\CompartmentContentNoteAggregate;
use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class CompartmentService
{
    public function __construct(
        private readonly CompartmentAccessService $accessService,
    ) {}

    /**
     * Get all compartments with their current contents.
     *
     * This is used by the mobile app to render a read-only overview.
     *
     * @return Collection<int, Compartment>
     */
    public function listWithContents(): Collection
    {
        return Compartment::query()
            ->with([
                'lockerBank',
            ])
            ->orderBy('locker_bank_id')
            ->orderBy('number')
            ->get();
    }

    /**
     * Record an auditable update to a compartment's free-text content note.
     *
     * The actor must have active access (direct or via a group) or be allowed
     * to manage compartment access operationally.
     * A null note clears the note. Returns the compartment with the new values
     * applied in memory (the read model is updated by CompartmentProjector).
     *
     * @throws AuthorizationException
     */
    public function updateContentNote(User $actor, Compartment $compartment, ?string $note): Compartment
    {
        $this->ensureCanEditNote($actor, $compartment);

        $updatedAt = now();

        CompartmentContentNoteAggregate::retrieve((string) $compartment->id)
            ->updateNote(
                actorUserId: $actor->id,
                compartmentUuid: (string) $compartment->id,
                note: $note,
                updatedAt: $updatedAt,
            )
            ->persist();

        return $compartment->forceFill([
            'content_note' => $note,
            'content_note_updated_at' => $updatedAt,
            'content_note_updated_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureCanEditNote(User $actor, Compartment $compartment): void
    {
        $canEdit = $actor->can(Permission::CompartmentAccessManage->value)
            || $this->accessService->hasActiveAccess($actor, $compartment);

        throw_unless($canEdit, AuthorizationException::class, 'You do not have access to this compartment.');
    }
}
