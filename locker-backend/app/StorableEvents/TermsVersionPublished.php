<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class TermsVersionPublished extends ShouldBeStored
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $version,
        public readonly string $content,
        public readonly ?int $publishedByUserId,
        public readonly string $publishedAt,
        public readonly ?string $documentNameSnapshot = null,
    ) {}
}
