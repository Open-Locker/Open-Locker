<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserAdministrationService
{
    public function canManageUser(User $actor, User $target): bool
    {
        if (! $actor->can(Permission::UsersManage->value)) {
            return false;
        }

        if ($actor->can(Permission::RolesManage->value)) {
            return true;
        }

        return ! $target->isAdmin();
    }

    /**
     * Set the target's single role. Role::User means "no stored role binding"
     * and clears all bindings. Extra roles a user may still hold from the old
     * multi-role UI are revoked, normalizing the user to one role.
     *
     * Returns false when the change would demote the last admin.
     *
     * @throws AuthorizationException
     */
    public function changeRole(User $actor, User $target, Role $role): bool
    {
        $this->ensureCanManageRoles($actor);

        $selected = $role === Role::User ? [] : [$role->value];
        $current = $target->roleNames();

        if (in_array(Role::Admin->value, $current, true)
            && ! in_array(Role::Admin->value, $selected, true)
            && ! User::hasOtherAdmin($target->id)) {
            return false;
        }

        foreach (array_diff($selected, $current) as $roleName) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                ->grantRole($target->id, $roleName, $actor->id, now())
                ->persist();
        }

        foreach (array_diff($current, $selected) as $roleName) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                ->revokeRole($target->id, $roleName, $actor->id, now())
                ->persist();
        }

        $target->flushPermissionCache();

        return true;
    }

    /**
     * @throws AuthorizationException
     */
    public function sendPasswordResetLink(User $actor, User $target): string
    {
        $this->ensureCanManageUser($actor, $target);

        return $target->sendAdminPasswordResetLink();
    }

    /**
     * @throws AuthorizationException
     */
    public function sendVerificationEmail(User $actor, User $target): bool
    {
        $this->ensureCanManageUser($actor, $target);

        return $target->sendAdminVerificationEmail();
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanManageUser(User $actor, User $target): void
    {
        throw_unless(
            $this->canManageUser($actor, $target),
            AuthorizationException::class,
            'You are not allowed to manage this user.'
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanManageRoles(User $actor): void
    {
        throw_unless(
            $actor->can(Permission::RolesManage->value),
            AuthorizationException::class,
            'You are not allowed to manage roles.'
        );
    }
}
