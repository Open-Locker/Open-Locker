<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Terms, 'Terms document created')]
class TermsDocumentCreated extends ShouldBeStored
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $name,
        public readonly ?int $createdByUserId,
        public readonly string $createdAt,
    ) {}
}
