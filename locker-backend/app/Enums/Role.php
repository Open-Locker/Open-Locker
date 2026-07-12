<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case User = 'user';
    case Manager = 'manager';
    case Admin = 'admin';

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
        };
    }

    /**
     * Roles that can be assigned through the generic role-management action.
     * `admin` is handled by dedicated guarded actions; `user` is the no-role default.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [
            self::Manager,
        ];
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
