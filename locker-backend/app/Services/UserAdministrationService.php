<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\LastAdminException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserAdministrationService
{
    public function __construct(private readonly LastAdminGuard $lastAdminGuard) {}

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

        $changed = $this->lastAdminGuard->attempt(function () use ($actor, $target, $selected): void {
            // Read the current roles inside the guarded transaction: a concurrent
            // request may have changed them since the form was rendered.
            $target->flushPermissionCache();
            $target->unsetRelation('userRoles');
            $current = $target->roleNames();

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
        });

        // Drop the memoized roles either way: on success they changed, and on a
        // rollback the pre-mutation read above must not be trusted.
        $target->flushPermissionCache();
        $target->unsetRelation('userRoles');

        return $changed;
    }

    /**
     * Delete the target user.
     *
     * Returns false when the deletion would leave no administrator.
     *
     * @throws AuthorizationException
     */
    public function deleteUser(User $actor, User $target): bool
    {
        return $this->deleteUsers($actor, [$target]);
    }

    /**
     * Delete several users as one all-or-nothing operation, so a selection can
     * never be split into "some deleted, no admin left".
     *
     * @param  iterable<int, User>  $targets
     *
     * @throws AuthorizationException
     */
    public function deleteUsers(User $actor, iterable $targets): bool
    {
        $targets = collect($targets);

        foreach ($targets as $target) {
            $this->ensureCanManageUser($actor, $target);
        }

        return $this->lastAdminGuard->attempt(function () use ($targets): void {
            foreach ($targets as $target) {
                // The model's deleting hook vetoes by returning false rather
                // than throwing, which would otherwise commit as a silent no-op.
                throw_unless($target->delete(), LastAdminException::class);
            }
        });
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
