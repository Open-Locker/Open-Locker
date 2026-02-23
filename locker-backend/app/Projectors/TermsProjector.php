<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\TermsDocument;
use App\Models\TermsDocumentVersion;
use App\Models\UserTermsAcceptance;
use App\StorableEvents\TermsDocumentCreated;
use App\StorableEvents\TermsVersionActivated;
use App\StorableEvents\TermsVersionPublished;
use App\StorableEvents\UserAcceptedTermsVersion;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class TermsProjector extends Projector
{
    public function onTermsDocumentCreated(TermsDocumentCreated $event): void
    {
        TermsDocument::query()->updateOrCreate(
            ['id' => $event->documentId],
            [
                'name' => $event->name,
                'created_at' => Carbon::parse($event->createdAt),
                'updated_at' => Carbon::parse($event->createdAt),
            ]
        );
    }

    public function onTermsVersionPublished(TermsVersionPublished $event): void
    {
        $snapshotName = $event->documentNameSnapshot
            ?? TermsDocument::query()->whereKey($event->documentId)->value('name')
            ?? 'Terms';

        TermsDocumentVersion::query()->updateOrCreate(
            ['id' => $event->versionId],
            [
                'terms_document_id' => $event->documentId,
                'version' => $event->version,
                'document_name_snapshot' => $snapshotName,
                'content' => $event->content,
                'is_published' => true,
                'published_at' => Carbon::parse($event->publishedAt),
                'created_by_user_id' => $event->publishedByUserId,
            ]
        );
    }

    public function onTermsVersionActivated(TermsVersionActivated $event): void
    {
        TermsDocumentVersion::query()
            ->where('terms_document_id', $event->documentId)
            ->update(['is_active' => false]);

        TermsDocumentVersion::query()
            ->whereKey($event->versionId)
            ->update(['is_active' => true]);
    }

    public function onUserAcceptedTermsVersion(UserAcceptedTermsVersion $event): void
    {
        UserTermsAcceptance::query()->updateOrCreate(
            [
                'user_id' => $event->userId,
                'terms_document_id' => $event->documentId,
                'terms_document_version_id' => $event->versionId,
            ],
            [
                'accepted_at' => Carbon::parse($event->acceptedAt),
            ]
        );
    }
}
