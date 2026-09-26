<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\OrganizationMembershipAggregate;
use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\LastAdminException;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Organizations\AddedToOrganizationNotification;
use App\Notifications\Organizations\RemovedFromOrganizationNotification;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserAdministrationService
{
    public function __construct(private readonly LastAdminGuard $lastAdminGuard) {}

    /**
     * Find an account by email regardless of case or surrounding spaces, so
     * `Anna@example.com` and ` anna@example.com` are the same person.
     */
    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->orderBy('id')
            ->first();
    }

    /**
     * Add a person to the organization being administered.
     *
     * An email that already has an account joins that account instead of
     * failing: the name typed here is ignored and the password is untouched,
     * because another organization may depend on both. The person is told by
     * email who added them. A new email creates the account as before.
     *
     * Check `wasRecentlyCreated` on the result to tell the two apart.
     *
     * @throws AuthorizationException
     */
    public function addUser(User $actor, string $firstName, string $lastName, string $email): User
    {
        throw_unless(
            $actor->can(Permission::UsersManage->value),
            AuthorizationException::class,
            'You are not allowed to manage users.',
        );

        $organization = app(OrganizationContext::class)->current();

        $user = DB::transaction(function () use ($actor, $firstName, $lastName, $email, $organization): User {
            $user = $this->findByEmail($email) ?? User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => trim($email),
                // Never shown: a new person sets their own through the reset link.
                'password' => Hash::make(Str::random(32)),
            ]);

            // Without a membership the account could sign in and then see an
            // empty app, since everything is scoped fail-closed.
            if (! $organization instanceof Organization) {
                return $user;
            }

            $user->organizations()->syncWithoutDetaching([
                $organization->id => ['joined_at' => now()],
            ]);

            $this->recordJoin($actor, $user, $organization, existingAccount: ! $user->wasRecentlyCreated);

            return $user;
        });

        // After the commit, so the email never announces a membership that was
        // rolled back.
        if (! $user->wasRecentlyCreated && $organization instanceof Organization) {
            $this->notifyAddedToOrganization($actor, $user, $organization);
        }

        return $user;
    }

    /**
     * Record in an organization's audit log that someone joined it. Recorded
     * inside that organization, which need not be the one being acted in: a
     * platform admin adds people to any organization.
     */
    public function recordJoin(User $actor, User $user, Organization $organization, bool $existingAccount): void
    {
        app(OrganizationContext::class)->runWithin($organization, fn () => OrganizationMembershipAggregate::retrieve(
            OrganizationMembershipAggregate::aggregateUuidFor($user->id, $organization->id)
        )->join(
            userId: $user->id,
            organizationId: $organization->id,
            actorUserId: $actor->id,
            existingAccount: $existingAccount,
            joinedAt: now(),
        )->persist());
    }

    /**
     * Remove a person from an organization, and the roles they held there with
     * the membership: left behind, re-adding them would silently restore those
     * roles. Returns false when they are its last administrator.
     *
     * Runs inside that organization, which need not be the one being acted in:
     * a platform admin removes people from any organization, and the roles and
     * the last-admin count both answer per organization.
     *
     * @throws AuthorizationException
     */
    public function removeFromOrganization(User $actor, User $target, Organization $organization): bool
    {
        return app(OrganizationContext::class)->runWithin($organization, function () use ($actor, $target, $organization): bool {
            $this->ensureCanManageRoles($actor);

            $removed = $this->lastAdminGuard->attempt(
                fn () => $this->detachFromCurrentOrganization($actor, $target, $organization),
            );

            // After the commit, so the email never announces a refused removal.
            if ($removed) {
                $this->notifyRemovedFromOrganization($actor, $target, $organization);
            }

            return $removed;
        });
    }

    /**
     * Take away everything the target had in the organization in context, then
     * the membership itself. Access and groups go too: left behind they would
     * still route that organization's door updates to the person, and come
     * back if they were ever added again. Each revocation is recorded.
     *
     * Runs inside the caller's last-admin guard.
     */
    private function detachFromCurrentOrganization(User $actor, User $target, Organization $organization): void
    {
        $accessService = app(CompartmentAccessService::class);
        foreach ($target->activeCompartmentAccesses()->with('compartment')->get() as $access) {
            if ($access->compartment !== null) {
                $accessService->revokeAccess($target, $access->compartment, $actor);
            }
        }

        $groupService = app(GroupAccessService::class);
        foreach ($target->activeGroups()->get() as $group) {
            $groupService->removeUser($group, $target, $actor);
        }

        $target->flushPermissionCache();
        $target->unsetRelation('userRoles');

        foreach ($target->roleNames() as $roleName) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                ->revokeRole($target->id, $roleName, $actor->id, now(), $organization->id)
                ->persist();
        }

        $target->organizations()->detach($organization->id);
    }

    /**
     * Tell someone they were removed from an organization and kept their
     * account. Replying reaches whoever removed them.
     */
    public function notifyRemovedFromOrganization(User $actor, User $user, Organization $organization): void
    {
        $user->notify(new RemovedFromOrganizationNotification(
            organizationName: $organization->name,
            actorName: $actor->fullName(),
            actorEmail: $actor->email,
        ));
    }

    /**
     * Tell an existing account it now belongs to another organization. Replying
     * reaches whoever added them.
     */
    public function notifyAddedToOrganization(User $actor, User $user, Organization $organization): void
    {
        $user->notify(new AddedToOrganizationNotification(
            organizationName: $organization->name,
            actorName: $actor->fullName(),
            actorEmail: $actor->email,
        ));
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if (! $actor->can(Permission::UsersManage->value)) {
            return false;
        }

        // Resetting a platform admin's password or deleting the account would
        // hand an organization admin the whole installation.
        if ($target->isPlatformAdmin() && ! $actor->isPlatformAdmin()) {
            return false;
        }

        if (! $this->sharesOnlyThisOrganization($actor, $target)) {
            return false;
        }

        if ($actor->can(Permission::RolesManage->value)) {
            return true;
        }

        return ! $target->isAdmin();
    }

    /**
     * Whether the target belongs to no operator other than the one being
     * administered.
     *
     * Managing a user here is identity-level, not membership-level: it changes
     * their email address, sends a password reset to it, and can delete the
     * account outright. A person who also belongs to another organization is
     * that organization's user too, and the last-admin guard only protects the
     * one being acted in — so an administrator of one operator could otherwise
     * take over or destroy an account another operator depends on.
     *
     * A platform administrator manages the installation and is exempt.
     */
    private function sharesOnlyThisOrganization(User $actor, User $target): bool
    {
        if ($actor->isPlatformAdmin()) {
            return true;
        }

        $currentOrganizationId = app(OrganizationContext::class)->currentId();

        if ($currentOrganizationId === null) {
            return false;
        }

        return ! $target->organizations()
            ->whereKeyNot($currentOrganizationId)
            ->exists();
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
        // Same boundary as canManageUser(): an organization admin must not
        // change what a platform admin is, even inside their own organization.
        throw_if(
            $target->isPlatformAdmin() && ! $actor->isPlatformAdmin(),
            AuthorizationException::class,
            'You are not allowed to manage this user.',
        );
        $this->ensureCanGrantPlatformAdmin($actor, $role);

        $selected = $role === Role::User ? [] : [$role->value];

        $organizationId = app(OrganizationContext::class)->currentId();

        // roleNames() answers for the current organization and deliberately
        // excludes platform_admin, so a diff built from it alone can never
        // revoke that role — demoting a platform admin would silently leave
        // their installation-wide access intact.
        $revokePlatformAdmin = $role !== Role::PlatformAdmin
            && $actor->isPlatformAdmin()
            && $target->isPlatformAdmin();

        $changed = $this->lastAdminGuard->attempt(function () use ($actor, $target, $selected, $organizationId, $revokePlatformAdmin): void {
            // Read the current roles inside the guarded transaction: a concurrent
            // request may have changed them since the form was rendered.
            $target->flushPermissionCache();
            $target->unsetRelation('userRoles');
            $current = $target->roleNames();

            // Inside the guard's lock, like the last-admin rule.
            throw_if(
                $revokePlatformAdmin && ! User::hasOtherPlatformAdmin([$target->id]),
                LastAdminException::class,
            );

            if ($revokePlatformAdmin) {
                // Recorded with a null organization, the way it was granted.
                UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                    ->revokeRole($target->id, Role::PlatformAdmin->value, $actor->id, now(), null)
                    ->persist();
            }

            foreach (array_diff($selected, $current) as $roleName) {
                UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                    ->grantRole($target->id, $roleName, $actor->id, now(), $roleName === Role::PlatformAdmin->value ? null : $organizationId)
                    ->persist();
            }

            foreach (array_diff($current, $selected) as $roleName) {
                UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($target->id))
                    ->revokeRole($target->id, $roleName, $actor->id, now(), $organizationId)
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
            $this->ensureCanDeleteUser($actor, $target);
        }

        $organization = app(OrganizationContext::class)->current();
        $removed = [];

        $done = $this->lastAdminGuard->attempt(function () use ($actor, $targets, $organization, &$removed): void {
            $removed = [];

            // Inside the guard's lock, so two deletions at once cannot each see
            // the other as the one that remains.
            $platformAdminIds = $targets->filter(fn (User $target): bool => $target->isPlatformAdmin())->pluck('id')->all();
            throw_if(
                $platformAdminIds !== [] && ! User::hasOtherPlatformAdmin($platformAdminIds),
                LastAdminException::class,
            );

            foreach ($targets as $target) {
                // An account is deleted only from its last organization.
                // Anyone else is removed from this one only, which looks the
                // same as a deletion, so an organization admin learns nothing
                // about other organizations.
                if ($organization instanceof Organization && $this->belongsElsewhere($target, $organization)) {
                    $this->detachFromCurrentOrganization($actor, $target, $organization);
                    $removed[] = $target;

                    continue;
                }

                // The model's deleting hook vetoes by returning false rather
                // than throwing, which would otherwise commit as a silent no-op.
                throw_unless($target->delete(), LastAdminException::class);
            }
        });

        // Only people who were removed rather than deleted still have an
        // account to write to; and only once the whole selection committed.
        if ($done && $organization instanceof Organization) {
            foreach ($removed as $target) {
                $this->notifyRemovedFromOrganization($actor, $target, $organization);
            }
        }

        return $done;
    }

    /**
     * Whether the actor may delete the target from the organization being
     * administered. Unlike identity changes (canManageUser), this is allowed for
     * someone who also belongs to another organization, because for them it
     * only removes the membership here.
     */
    public function canDeleteUser(User $actor, User $target): bool
    {
        if (! $actor->can(Permission::UsersManage->value)) {
            return false;
        }

        if ($target->isPlatformAdmin() && ! $actor->isPlatformAdmin()) {
            return false;
        }

        $organizationId = app(OrganizationContext::class)->currentId();

        // Deleting acts on the membership here, so the person must have one;
        // for a platform admin too, who otherwise could "remove" someone from
        // an organization they never belonged to.
        if ($organizationId === null || ! $target->organizations()->whereKey($organizationId)->exists()) {
            return false;
        }

        return $actor->can(Permission::RolesManage->value) || ! $target->isAdmin();
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanDeleteUser(User $actor, User $target): void
    {
        throw_unless(
            $this->canDeleteUser($actor, $target),
            AuthorizationException::class,
            'You are not allowed to delete this user.',
        );
    }

    /**
     * Whoever deletes: an account is deleted only from its last organization.
     */
    private function belongsElsewhere(User $target, Organization $organization): bool
    {
        return $target->organizations()->whereKeyNot($organization->id)->exists();
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
     * platform_admin administers the installation, not an organization, so
     * granting it is not part of managing your own users. Without this an
     * organization admin — who holds roles.manage by definition — could mint
     * someone with full read and write inside every other operator, and the
     * entry recording from ADR-0065 would only show it after the fact.
     *
     * Enforced in the service rather than only in the form: the panel is not the
     * only caller.
     *
     * @throws AuthorizationException
     */
    private function ensureCanGrantPlatformAdmin(User $actor, Role $role): void
    {
        throw_unless(
            $role !== Role::PlatformAdmin || $actor->isPlatformAdmin(),
            AuthorizationException::class,
            'Only a platform administrator may grant platform administration.'
        );
    }

    public function ensureCanManageRoles(User $actor): void
    {
        throw_unless(
            $actor->can(Permission::RolesManage->value),
            AuthorizationException::class,
            'You are not allowed to manage roles.'
        );
    }
}
