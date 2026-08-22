<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\CompartmentContentNoteAggregate;
use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class CompartmentService
{
    /** Matches the `content_note` column and both entry-point validations. */
    public const CONTENT_NOTE_MAX_LENGTH = 80;

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
     * A null note clears the note. CompartmentProjector runs synchronously
     *, so the read model is already persisted when this returns; we
     * reload it rather than faking the response with in-memory values.
     *
     * @throws AuthorizationException
     */
    public function updateContentNote(User $actor, Compartment $compartment, ?string $note): Compartment
    {
        $this->ensureCanEditNote($actor, $compartment);

        // The Filament form and the API request each cap this at 80, matching the
        // column. Enforced here as well because what gets past this point becomes
        // a stored event: a caller that skipped validation would record permanent
        // history the read model cannot hold, failing only when the projector
        // writes it.
        if ($note !== null && mb_strlen($note) > self::CONTENT_NOTE_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Content note may not exceed %d characters.', self::CONTENT_NOTE_MAX_LENGTH),
            );
        }

        CompartmentContentNoteAggregate::retrieve((string) $compartment->id)
            ->updateNote(
                actorUserId: $actor->id,
                compartmentUuid: (string) $compartment->id,
                note: $note,
                updatedAt: now(),
            )
            ->persist();

        return $compartment->refresh();
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
