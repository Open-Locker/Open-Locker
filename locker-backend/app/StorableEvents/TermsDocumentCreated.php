<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class TermsDocumentCreated extends ShouldBeStored
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $name,
        public readonly ?int $createdByUserId,
        public readonly string $createdAt,
    ) {}
}
