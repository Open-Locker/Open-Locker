<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Attribute;

/**
 * This stored event is deliberately kept out of the admin audit log, for the
 * reason given, typically high-volume telemetry or internal transport traffic
 * whose outcome another, audited event records (#202).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NotAudited
{
    public function __construct(
        public readonly string $reason,
    ) {}
}
