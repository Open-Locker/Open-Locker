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
     * @return list<string>
     */
    public function assignableRoleNames(): array
    {
        return array_map(static fn (Role $role): string => $role->value, Role::assignable());
    }

    /**
     * @param  list<string>  $selectedRoles
     *
     * @throws AuthorizationException
     */
    public function syncAssignableRoles(User $actor, User $target, array $selectedRoles): void
    {
        $this->ensureCanManageRoles($actor);

        $assignable = $this->assignableRoleNames();
        $selected = array_values(array_intersect($selectedRoles, $assignable));
        $current = array_values(array_intersect($target->roleNames(), $assignable));

        foreach (array_diff($selected, $current) as $role) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                ->grantRole($target->id, $role, $actor->id, now())
                ->persist();
        }

        foreach (array_diff($current, $selected) as $role) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                ->revokeRole($target->id, $role, $actor->id, now())
                ->persist();
        }

        $target->flushPermissionCache();
    }

    /**
     * @throws AuthorizationException
     */
    public function makeAdmin(User $actor, User $target): void
    {
        $this->ensureCanManageRoles($actor);

        $target->makeAdmin($actor->id);
    }

    /**
     * @throws AuthorizationException
     */
    public function removeAdmin(User $actor, User $target): bool
    {
        $this->ensureCanManageRoles($actor);

        if (! User::hasOtherAdmin($target->id)) {
            return false;
        }

        $target->removeAdmin($actor->id);

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
