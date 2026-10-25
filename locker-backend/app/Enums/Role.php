<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case User = 'user';
    case Manager = 'manager';
    case Admin = 'admin';
    case PlatformAdmin = 'platform_admin';

    public function label(): string
    {
        return match ($this) {
            self::User => __('User'),
            self::Manager => __('Manager'),
            self::Admin => __('Administrator'),
            self::PlatformAdmin => __('Platform administrator'),
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::User => [],
            self::Manager => [
                Permission::PanelAccess,
                Permission::UsersManage,
                Permission::GroupsManage,
                Permission::CompartmentAccessManage,
                Permission::CompartmentOpen,
                Permission::SystemConfigure,
            ],
            self::Admin => Permission::cases(),
            // Everything an organization admin can do, in whichever
            // organization is currently being acted in, plus managing the
            // organizations themselves.
            self::PlatformAdmin => Permission::cases(),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function valuesWithPermission(Permission $permission): array
    {
        return array_values(array_map(
            static fn (self $role): string => $role->value,
            array_filter(
                self::cases(),
                static fn (self $role): bool => in_array($permission, $role->permissions(), true),
            ),
        ));
    }
}
