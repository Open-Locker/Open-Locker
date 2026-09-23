<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Events\TermsAcceptanceRequired;
use App\Models\TermsDocument;
use App\Models\User;
use App\Notifications\Terms\TermsVersionPublishedNotification;
use App\StorableEvents\TermsVersionActivated;
use App\StorableEvents\TermsVersionPublished;
use App\Support\EventSourcing\OrganizationStamp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class TermsNotificationReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function onTermsVersionPublished(TermsVersionPublished $event): void
    {
        // Queued, so there is no request to read an organization from: it comes
        // from the event, which carries where it happened.
        $organizationId = OrganizationStamp::from($event);

        $document = TermsDocument::withoutGlobalScope('organization')->find($event->documentId);
        $documentName = $document !== null ? $document->name : 'Terms';

        $this->membersOf($organizationId)
            ->select(['users.id', 'users.email'])
            ->chunkById(250, function ($users) use ($documentName, $event): void {
                Notification::send(
                    $users,
                    new TermsVersionPublishedNotification(
                        documentName: $documentName,
                        version: $event->version
                    )
                );
            });
    }

    /**
     * Activation, not publication, is what makes acceptance required: a user's
     * `terms_current_accepted` compares their acceptance against the *active*
     * version. Today one service call records both, so the two moments coincide —
     * but an activate-an-existing-version path would leave apps holding a stale
     * profile if this hung off publication instead.
     */
    public function onTermsVersionActivated(TermsVersionActivated $event): void
    {
        $this->membersOf(OrganizationStamp::from($event))
            ->select(['users.id'])
            ->chunkById(250, function ($users) use ($event): void {
                // One broadcast per chunk rather than per user: the event maps its
                // recipients to a channel each, so a chunk is a single dispatch.
                event(new TermsAcceptanceRequired(
                    recipientUserIds: array_values($users->pluck('id')->map(fn ($id) => (int) $id)->all()),
                    version: $event->version,
                ));
            });
    }

    /**
     * Terms belong to one operator, so only that operator's people are told
     * about them. Notifying everyone would announce one organization's legal
     * changes to another's users.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function membersOf(?string $organizationId): Builder
    {
        return User::query()->whereHas(
            'organizations',
            fn (Builder $query) => $query->whereKey($organizationId),
        );
    }
}
