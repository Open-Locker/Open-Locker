<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Models\Organization;

/**
 * The one place that answers "which organization am I acting in".
 *
 * Request-scoped and resolved once: middleware sets it from the header (or from
 * the user's sole membership), Filament sets it from the panel tenant, and a
 * queued job is given it explicitly. Nothing discovers it on its own — work that
 * runs outside a request has no ambient answer to find, so it is passed in.
 */
class OrganizationContext
{
    private ?Organization $current = null;

    public function set(?Organization $organization): void
    {
        $this->current = $organization;
    }

    public function current(): ?Organization
    {
        return $this->current;
    }

    public function currentId(): ?string
    {
        return $this->current?->id;
    }

    public function has(): bool
    {
        return $this->current !== null;
    }

    /**
     * Run a callback as if the given organization were current, restoring
     * whatever was current before. Used by console commands and by tests that
     * need to act across organizations deliberately.
     */
    public function runWithin(?Organization $organization, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $organization;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
