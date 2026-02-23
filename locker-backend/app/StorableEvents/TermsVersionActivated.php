<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class TermsVersionActivated extends ShouldBeStored
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $version,
        public readonly ?int $activatedByUserId,
        public readonly string $activatedAt,
    ) {}
}
