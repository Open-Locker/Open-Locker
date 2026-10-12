<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Terms, 'Terms version published')]
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
