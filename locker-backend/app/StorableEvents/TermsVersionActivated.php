<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Terms, 'Terms version activated')]
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
