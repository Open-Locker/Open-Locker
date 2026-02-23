<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\TermsDocumentCreated;
use App\StorableEvents\TermsVersionActivated;
use App\StorableEvents\TermsVersionPublished;
use App\StorableEvents\UserAcceptedTermsVersion;
use Carbon\CarbonInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class TermsDocumentAggregate extends AggregateRoot
{
    private bool $documentCreated = false;

    private int $nextVersion = 1;

    /** @var array<int, bool> */
    private array $publishedVersionIds = [];

    private ?int $activeVersionId = null;

    private ?int $activeVersionNumber = null;

    public static function aggregateUuidFor(int $documentId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "terms-document:{$documentId}")->toString();
    }

    public function createDocument(
        int $documentId,
        string $name,
        ?int $createdByUserId,
        CarbonInterface $createdAt,
    ): self {
        if ($this->documentCreated) {
            return $this;
        }

        $this->recordThat(new TermsDocumentCreated(
            documentId: $documentId,
            name: $name,
            createdByUserId: $createdByUserId,
            createdAt: $createdAt->toIso8601String(),
        ));

        return $this;
    }

    public function publishNextVersion(
        int $documentId,
        int $versionId,
        int $version,
        string $documentNameSnapshot,
        string $content,
        ?int $publishedByUserId,
        CarbonInterface $publishedAt,
    ): self {
        if (isset($this->publishedVersionIds[$versionId])) {
            throw new LogicException('This terms version has already been published.');
        }

        if ($version !== $this->nextVersion) {
            throw new LogicException('Terms version sequence mismatch. Publish the oldest draft first.');
        }

        $this->recordThat(new TermsVersionPublished(
            documentId: $documentId,
            versionId: $versionId,
            version: $version,
            documentNameSnapshot: $documentNameSnapshot,
            content: $content,
            publishedByUserId: $publishedByUserId,
            publishedAt: $publishedAt->toIso8601String(),
        ));

        return $this;
    }

    public function activateVersion(
        int $documentId,
        int $versionId,
        int $version,
        ?int $activatedByUserId,
        CarbonInterface $activatedAt,
    ): self {
        if (! isset($this->publishedVersionIds[$versionId])) {
            throw new LogicException('Only published terms versions can be activated.');
        }

        if ($this->activeVersionId === $versionId) {
            return $this;
        }

        $this->recordThat(new TermsVersionActivated(
            documentId: $documentId,
            versionId: $versionId,
            version: $version,
            activatedByUserId: $activatedByUserId,
            activatedAt: $activatedAt->toIso8601String(),
        ));

        return $this;
    }

    public function acceptActiveVersion(
        int $documentId,
        int $userId,
        CarbonInterface $acceptedAt,
    ): self {
        if ($this->activeVersionId === null || $this->activeVersionNumber === null) {
            throw new LogicException('No active terms version is available.');
        }

        $this->recordThat(new UserAcceptedTermsVersion(
            documentId: $documentId,
            versionId: $this->activeVersionId,
            version: $this->activeVersionNumber,
            userId: $userId,
            acceptedAt: $acceptedAt->toIso8601String(),
        ));

        return $this;
    }

    public function applyTermsDocumentCreated(TermsDocumentCreated $event): void
    {
        $this->documentCreated = true;
    }

    public function applyTermsVersionPublished(TermsVersionPublished $event): void
    {
        $this->publishedVersionIds[$event->versionId] = true;
        $this->nextVersion = $event->version + 1;
    }

    public function applyTermsVersionActivated(TermsVersionActivated $event): void
    {
        $this->activeVersionId = $event->versionId;
        $this->activeVersionNumber = $event->version;
    }
}
