<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Attribute;

/**
 * This stored event is shown in the admin audit log (ADR-0030).
 *
 * Every class in app/StorableEvents carries either this or {@see NotAudited};
 * AuditEventClassificationTest fails otherwise, so a new event cannot silently
 * go missing from the log (#202).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Audited
{
    public function __construct(
        public readonly AuditCategory $category,
        /** English source string, translated with __() when shown. */
        public readonly string $label,
    ) {}
}
