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
    /**
     * There is exactly one terms document and it changes rarely, but the gates
     * ask for it repeatedly: the middleware needs the active version and then
     * the acceptance predicate, and a refused open asks a third time to build
     * the response. Holding it for the life of the instance keeps that at one
     * lookup. A separate flag is needed because "no document yet" is a real
     * answer that must not be re-queried on every call.
     */
    private ?TermsDocument $currentDocument = null;

    private bool $currentDocumentLoaded = false;

    public function getCurrentDocument(): ?TermsDocument
    {
        if ($this->currentDocumentLoaded) {
            return $this->currentDocument;
        }

        $this->currentDocument = TermsDocument::query()
            ->with('activeVersion')
            ->oldest('id')
            ->first();
        $this->currentDocumentLoaded = true;

        return $this->currentDocument;
    }

    /**
     * The version of the terms that is in force right now, or null while none
     * has been published.
     */
    public function activeVersion(): ?TermsDocumentVersion
    {
        return $this->getCurrentDocument()?->activeVersion;
    }

    /**
     * Whether the user has accepted the version that is in force right now.
     *
     * The match is on the version's id, not its number. The two differ when a
     * version is rolled back: a user who accepted v2 has not accepted v1, even
     * though their acceptance is the more recent one and v1 is the lower
     * number. Nothing published means nothing to accept, so that answers true.
     *
     * Every gate on terms acceptance reads this one predicate, so a route that
     * checks it inline cannot quietly disagree with the middleware.
     */
    public function hasAcceptedActiveVersion(User $user): bool
    {
        $activeVersion = $this->activeVersion();

        if (! $activeVersion) {
            return true;
        }

        return $user->termsAcceptances()
            ->where('terms_document_id', $activeVersion->terms_document_id)
            ->where('terms_document_version_id', $activeVersion->id)
            ->exists();
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
        $draft = DB::transaction(function () use ($documentName, $content, $actor): TermsDocumentVersion {
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

        // The document may have just been created, or renamed.
        $this->forgetCurrentDocument();

        return $draft;
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

        // A different version is in force now.
        $this->forgetCurrentDocument();

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

    /**
     * Drop the held document after anything that changes which version is in
     * force, so a caller that publishes and then reads within one request is
     * not answered from before its own write.
     */
    private function forgetCurrentDocument(): void
    {
        $this->currentDocument = null;
        $this->currentDocumentLoaded = false;
    }

    private function aggregate(int $documentId): TermsDocumentAggregate
    {
        return TermsDocumentAggregate::retrieve(
            TermsDocumentAggregate::aggregateUuidFor($documentId)
        );
    }
}
