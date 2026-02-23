<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Models\TermsDocument;
use App\Models\User;
use App\Notifications\Terms\TermsVersionPublishedNotification;
use App\StorableEvents\TermsVersionPublished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class TermsNotificationReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function onTermsVersionPublished(TermsVersionPublished $event): void
    {
        $document = TermsDocument::query()->find($event->documentId);
        $documentName = $document?->name ?? 'Terms';

        User::query()
            ->select(['id', 'email', 'name'])
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
}
