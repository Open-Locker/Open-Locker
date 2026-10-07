<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Models\Organization;

/**
 * The organization that owns everything predating multi-organization support.
 *
 * Events recorded before organizations existed carry no organization, and they
 * are immutable — so replay has to answer the question for them. It always
 * answers with this one, permanently: creating a second organization later
 * never reassigns history.
 */
class DefaultOrganization
{
    public const SLUG = 'default';

    private static ?string $cachedId = null;

    public static function id(): ?string
    {
        return self::$cachedId ??= Organization::query()->where('slug', self::SLUG)->value('id');
    }

    public static function forget(): void
    {
        self::$cachedId = null;
    }
}
