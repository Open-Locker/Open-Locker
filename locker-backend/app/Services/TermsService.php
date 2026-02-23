<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\TermsDocumentAggregate;
use App\Models\TermsDocument;
use App\Models\TermsDocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

class TermsService
{
    public function getCurrentDocument(): ?TermsDocument
    {
        return TermsDocument::query()
            ->with('activeVersion')
            ->oldest('id')
            ->first();
    }

    /**
     * Publish a new immutable terms version and activate it.
     */
    public function publishNewVersion(string $documentName, string $content, ?User $actor = null): TermsDocumentVersion
    {
        $draft = $this->createDraftVersion($documentName, $content, $actor);

        return $this->publishDraftVersion($draft, $actor);
    }

    public function createDraftVersion(string $documentName, string $content, ?User $actor = null): TermsDocumentVersion
    {
        return DB::transaction(function () use ($documentName, $content, $actor): TermsDocumentVersion {
            $document = TermsDocument::query()
                ->lockForUpdate()
                ->oldest('id')
                ->first();

            if (! $document) {
                $document = TermsDocument::query()->create([
                    'name' => $documentName,
                ]);

                $this->aggregate($document->id)
                    ->createDocument(
                        documentId: $document->id,
                        name: $document->name,
                        createdByUserId: $actor?->id,
                        createdAt: now(),
                    )
                    ->persist();
            } elseif ($document->name !== $documentName) {
                $document->update(['name' => $documentName]);
            }

            $drafts = TermsDocumentVersion::query()
                ->where('terms_document_id', $document->id)
                ->where('is_published', false)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->get();

            if ($drafts->count() > 1) {
                throw new LogicException('Multiple drafts detected. Keep only one draft per document.');
            }

            $existingDraft = $drafts->first();

            if ($existingDraft) {
                $existingDraft->update([
                    'document_name_snapshot' => $document->name,
                    'content' => $content,
                    'created_by_user_id' => $actor?->id,
                ]);

                return $existingDraft->refresh();
            }

            $nextVersion = ((int) $document->versions()->max('version')) + 1;

            return TermsDocumentVersion::query()->create([
                'terms_document_id' => $document->id,
                'document_name_snapshot' => $document->name,
                'version' => $nextVersion,
                'content' => $content,
                'is_published' => false,
                'is_active' => false,
                'created_by_user_id' => $actor?->id,
            ]);
        });
    }

    public function publishDraftVersion(TermsDocumentVersion $draft, ?User $actor = null): TermsDocumentVersion
    {
        if ($draft->is_published) {
            throw new LogicException('This version is already published.');
        }

        $document = TermsDocument::query()->find($draft->terms_document_id);
        if (! $document) {
            throw new ModelNotFoundException('Terms document not found for this draft.');
        }

        $documentNameSnapshot = (string) ($draft->document_name_snapshot ?: $document->name);

        $this->aggregate($document->id)
            ->publishNextVersion(
                documentId: $document->id,
                versionId: $draft->id,
                version: $draft->version,
                documentNameSnapshot: $documentNameSnapshot,
                content: $draft->content,
                publishedByUserId: $actor?->id,
                publishedAt: now(),
            )
            ->activateVersion(
                documentId: $document->id,
                versionId: $draft->id,
                version: $draft->version,
                activatedByUserId: $actor?->id,
                activatedAt: now(),
            )
            ->persist();

        return $draft->refresh();
    }

    /**
     * Record acceptance of the active terms version for the given user.
     */
    public function acceptCurrentTerms(User $user): TermsDocumentVersion
    {
        $document = $this->getCurrentDocument();
        $activeVersion = $document?->activeVersion;

        if (! $document || ! $activeVersion) {
            throw new ModelNotFoundException('No active terms version exists.');
        }

        $this->aggregate($document->id)
            ->acceptActiveVersion(
                documentId: $document->id,
                userId: $user->id,
                acceptedAt: now(),
            )
            ->persist();

        return $activeVersion;
    }

    /**
     * Ensure published versions remain immutable.
     */
    public function updateUnpublishedVersion(TermsDocumentVersion $version, string $content): TermsDocumentVersion
    {
        if ($version->is_published) {
            throw new LogicException('Published terms versions are immutable.');
        }

        $version->update(['content' => $content]);

        return $version->refresh();
    }

    private function aggregate(int $documentId): TermsDocumentAggregate
    {
        return TermsDocumentAggregate::retrieve(
            TermsDocumentAggregate::aggregateUuidFor($documentId)
        );
    }
}
