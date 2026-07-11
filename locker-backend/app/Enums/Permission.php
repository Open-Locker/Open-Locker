<?php

declare(strict_types=1);

namespace App\Enums;

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

    public function description(): string
    {
        return match ($this) {
            self::PanelAccess => 'Allows a user to sign in to the Filament admin panel.',
            self::UsersManage => 'Allows viewing and managing user records; manager mutations are scoped to non-admin users.',
            self::GroupsManage => 'Allows managing groups, memberships, and group compartment access.',
            self::CompartmentAccessManage => 'Allows granting and revoking direct compartment access for users.',
            self::CompartmentOpen => 'Allows operationally opening any compartment and receiving operational status updates.',
            self::RolesManage => 'Allows granting and revoking user roles.',
            self::LockerBankConfigure => 'Allows changing technical locker-bank configuration like Modbus, provisioning, and heartbeat settings.',
            self::SystemConfigure => 'Allows changing legal and system-wide configuration resources.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
