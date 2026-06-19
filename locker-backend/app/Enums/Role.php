<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type-safe references to the role catalog (config/authorization.yaml).
 *
 * Kept in sync with the YAML by AuthorizationCatalogTest. `Admin` is the
 * super-role (see ADR-0021): it bypasses permission checks via Gate::before.
 */
enum Role: string
{
    case User = 'user';
    case Manager = 'manager';
    case Admin = 'admin';
}
