<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type-safe references to the permission catalog (config/authorization.yaml).
 *
 * Code checks permissions via these cases (e.g. `$user->can(Permission::CompartmentOpen->value)`).
 * A parity test (AuthorizationCatalogTest) asserts these cases and the YAML stay
 * in sync, so removing a still-referenced permission from the YAML fails CI.
 */
enum Permission: string
{
    case PanelAccess = 'panel.access';
    case UsersManage = 'users.manage';
    case GroupsManage = 'groups.manage';
    case CompartmentAccessManage = 'compartment.access.manage';
    case CompartmentOpen = 'compartment.open';
    case RolesManage = 'roles.manage';
    case LockerBankConfigure = 'lockerbank.configure';
    case SystemConfigure = 'system.configure';
}
