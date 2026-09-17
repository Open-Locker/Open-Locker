<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use RuntimeException;

/**
 * Console commands say which organization they act in.
 *
 * There is no request to read it from, and discovering it from ambient state is
 * how a command ends up operating on the wrong operator. A command given no
 * organization acts on nothing rather than on everything — the same fail-closed
 * rule the request header follows.
 */
trait ActsWithinOrganization
{
    protected function resolveOrganizationFromOption(): Organization
    {
        $identifier = $this->option('organization');

        if (! is_string($identifier) || $identifier === '') {
            throw new RuntimeException(
                'This command acts inside one organization. Pass --organization=<slug|id>.'
            );
        }

        $organization = Organization::query()
            ->where('slug', $identifier)
            ->orWhere('id', $identifier)
            ->first();

        if (! $organization instanceof Organization) {
            throw new RuntimeException("No organization matches [{$identifier}].");
        }

        app(OrganizationContext::class)->set($organization);

        return $organization;
    }

    /**
     * Run a callback once per organization, each with its own context. For
     * maintenance that genuinely spans the installation — stated explicitly,
     * rather than falling out of an unset context.
     */
    protected function forEachOrganization(callable $callback): void
    {
        $context = app(OrganizationContext::class);

        Organization::query()->orderBy('name')->each(
            fn (Organization $organization) => $context->runWithin(
                $organization,
                fn () => $callback($organization),
            )
        );
    }
}
