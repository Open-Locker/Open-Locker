<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MissingOrganizationContextException;
use App\Models\Organization;

/**
 * The organization the current admin-panel request or CLI command acts in
 * (ADR-0067). Until tenancy lands this is always the default organization.
 * Projectors must never read it.
 */
class OrganizationContext
{
    public function current(): ?Organization
    {
        return Organization::query()->where('slug', Organization::DEFAULT_SLUG)->first();
    }

    /**
     * @throws MissingOrganizationContextException
     */
    public function require(): Organization
    {
        return $this->current() ?? throw new MissingOrganizationContextException;
    }
}
