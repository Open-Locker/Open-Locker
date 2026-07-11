# ADR-0021: Capability-based access control with static role-permission bindings

## Status

Accepted

## Date

2026-06-17

## Context

Authorization previously used a single binary flag. `users.is_admin_since`
backed `User::isAdmin()`, and that predicate was checked ad hoc across panel
access, API middleware, services, Filament resources, and domain workflows. The
model was strictly admin / not-admin.

#95 requires a `manager` role for day-to-day operations: a manager may manage
regular users, manage compartment access, and open any compartment, but must not
grant elevated roles, change locker-bank technical configuration, or touch
legal/system configuration. Admin must remain a strict superset of manager.

The core problem is structural: role-identity checks do not compose. Adding
`manager` by scattering `isAdmin() || isManager()` style logic would make the
role-capability mapping hard to audit and easy to miss at individual call sites.

The implementation also needs an audit trail for user-role assignment changes.
Changing which users hold `admin` or `manager` is operational domain state, so it
fits the existing aggregate -> event -> projector pattern. By contrast, roles,
permissions, and role-permission bindings are code-owned authorization
configuration: the application checks specific permission names, and changing
what a role can do should be reviewed and deployed with the code that depends on
those permissions.

## Decision

Introduce a capability-based authorization model built on Laravel Gate. Code
checks permissions, not role identity.

### Static Authorization Enums

The backend enums are the single source of truth for:

- The permissions the application may check.
- The roles that may be assigned to users.
- The static role -> permission bindings used at runtime.

Example:

```php
enum Permission: string
{
    case PanelAccess = 'panel.access';
    case UsersManage = 'users.manage';

    public function description(): string
    {
        return match ($this) {
            self::PanelAccess => 'Allows a user to sign in to the Filament admin panel.',
            self::UsersManage => 'Allows viewing and managing user records...',
        };
    }
}

enum Role: string
{
    case User = 'user';
    case Manager = 'manager';
    case Admin = 'admin';

    public function permissions(): array
    {
        return match ($this) {
            self::User => [],
            self::Manager => [
                Permission::PanelAccess,
                Permission::UsersManage,
                Permission::CompartmentAccessManage,
                Permission::CompartmentOpen,
            ],
            self::Admin => Permission::cases(),
        };
    }
}
```

The enum model is developer-owned and version-controlled. There is no admin UI for
editing roles, permissions, or role-permission bindings at runtime. Changes to
the map require a code change, review, and deploy. Permission descriptions live
on `Permission::description()` so reviewers can understand the intended scope
without looking up call sites first. `Role::permissions()` provides the static
role -> permission bindings with IDE/refactor support and no duplicate config.

### Event-Sourced User-Role Assignments

The database/event stream tracks only user -> role assignments:

- `UserRoleAggregate` records `UserRoleGranted` and `UserRoleRevoked`.
- `UserRoleProjector` maintains the `user_roles` read model.
- Filament user administration may assign or revoke roles, but it records events
  instead of writing the read model directly.

Runtime-editable role -> permission bindings are intentionally deferred. There
is no `role_permissions` read model, no `RoleAggregate`, no
`RolePermissionGranted` / `RolePermissionRevoked` events, and no per-role
permission-management UI in this accepted direction.

### Enforcement

`HasPermissions` resolves a user's effective permissions by loading their roles
from `user_roles` and applying the static bindings from `Role::permissions()`.

`AuthorizationServiceProvider` registers one `Gate::before` hook:

- `admin` is the super-role and passes every ability check unconditionally.
- Any ability that exists in the `Permission` enum resolves through
  `$user->hasPermission($ability)`.
- Unknown abilities fall through to normal Gates and Policies.

This keeps Laravel's standard authorization API unchanged:

```php
$user->can(Permission::CompartmentOpen->value);
$this->authorize(Permission::UsersManage->value);
->middleware('can:panel.access');
```

### Bootstrap and Legacy Admins

The first-user bootstrap is represented as a system-initiated
`UserRoleGranted(admin)` event. `isAdmin()` is a thin role-identity helper over
the event-sourced `user_roles` read model for places that need to reason about
the edited user's role identity rather than an actor's capability.

`users.is_admin_since` is not retained as a second source of truth. The deploy
migration `2026_07_11_000001_backfill_admin_roles_and_drop_is_admin_since`
backfills legacy admins into `user_roles` through `UserRoleAggregate`, then drops
the column. Normal seeders do not backfill production authorization data. The
migration should be tested against a production-like database before rollout;
rollback recreates the legacy column from currently-held admin roles and cannot
restore historical revoked admin timestamps.

### Open Authorization Type

Manager-initiated compartment opens use `authorizationType:
'manager_override'`, parallel to the existing `admin_override`,
`granted_access`, and `group_access` values from ADR-0020. Realtime compartment
status broadcasts include users with direct/group access and users whose roles
hold `compartment.open` (currently admins and managers), so operational managers
see state changes for compartments they are allowed to operate.

### Management UI

The admin UI supports assigning roles to users for actors with `roles.manage`.
It does not support editing role-permission bindings. The role-permission map is
intentionally managed in the `Role` enum.

Filament actions delegate sensitive user mutations to services so authorization
is enforced server-side, independent of button visibility. Manager-safe user
management uses `UserAdministrationService`; compartment access grants/revokes
are guarded in `CompartmentAccessService`.

Managers hold `users.manage` so they can manage regular user records and
compartment access for those users. They do not hold `roles.manage`,
`lockerbank.configure`, `groups.manage`, or `system.configure`. Filament user
management enforces this at record level: managers may list and view users with
the `admin` role, but cannot edit, delete, reset credentials for, grant
compartment access to, or otherwise mutate those accounts, and they cannot
assign or revoke roles. Admins remain the super-role and can manage all user
records.

Managers also hold `compartment.access.manage`; in addition to direct access
grant/revoke workflows, this covers operational content-note maintenance for any
compartment. Regular users may still update notes only for compartments they can
access directly or through a group.

The legacy `/api/admin/*` endpoints are removed from this slice because they are
not currently used and did not have manager-safe requirements. Future API user
management should be reintroduced with explicit permission-scoped contracts
rather than restoring the old binary admin controller.

## Alternatives Considered

### Alternative A: `spatie/laravel-permission`

- Pros: mature package, prebuilt role/permission helpers, native Gate
  integration, permission caching.
- Cons: roles and permissions are database rows, creating a second source of
  truth beside the code-owned enums; role assignment changes still need the event
  store for audit; runtime CRUD encourages editing authorization structure that
  this project keeps code-owned.
- Why not chosen: the project needs a small, explicit resolver over static
  enums and event-sourced user-role assignments, not a second authorization
  data model.

### Alternative B: Runtime-editable role-permission bindings

- Pros: admins could change what a manager may do without a deploy.
- Cons: more moving parts (`role_permissions`, aggregate, events, projector,
  UI, cache invalidation, replay concerns) for a small permission set; a runtime
  toggle can drift from code expectations and changes operational blast radius
  without code review.
- Why not chosen: deferred until there is a clear product need. For now,
  role-permission changes are code-reviewed enum changes.

### Alternative C: Role-identity checks

- Pros: slightly less upfront setup.
- Cons: scatters the role -> capability mapping across call sites and makes
  each future role change touch unrelated code.
- Why not chosen: permissions are the indirection that keeps authorization
  centralized and testable.

### Alternative D: `bezhanSalleh/filament-shield`

- Pros: ready-made Filament UI for roles and permissions.
- Cons: builds on spatie, generates CRUD-shaped permissions, and provides a
  runtime editing model that conflicts with this ADR's static enum model.
- Why not chosen: too much machinery for a 3-role / small-permission system.

## Consequences

### Positive

- Authorization composes: code asks "can this user do X?" and the role ->
  permission map lives in reviewed PHP enums.
- No role-permission database table, projector, aggregate, or admin UI is needed
  for the current product shape.
- User-role assignment remains auditable and replayable through event sourcing.
- Admin remains a strict super-role by construction.
- Native Laravel Gate keeps Filament, `authorize()`, middleware, and Blade
  integration idiomatic.
- `users.is_admin_since` is removed, eliminating the legacy dual source of
  truth for admin status.

### Negative

- Changing what a role can do requires a deploy.
- The team owns the small enum-based resolver instead of delegating to a package.
- If runtime permission tuning becomes a real need later, it will require a new
  ADR and a deliberate data model/UI design.

### Risks

- A missed call site could keep relying on `isAdmin()` when it should check a
  permission. Mitigation: migrate call sites behind `Permission` enum checks and
  cover manager allow-list/admin-only denials with tests.
- Enum drift could break permission checks. Mitigation: focused authorization
  tests assert manager/admin bindings and permission descriptions.
- Read-model drift for user roles could affect effective permissions.
  Mitigation: `UserRoleProjector` is the only writer of `user_roles`, and the
  table is rebuildable by replay.

## Rollout / Migration

1. Add `Permission` / `Role` enums with static role bindings, `HasPermissions`,
   and the `Gate::before` integration.
2. Add event-sourced user-role assignments (`UserRoleAggregate`, events,
   `UserRoleProjector`, `user_roles` migration), backfill legacy
   `is_admin_since` admins in a deploy migration, and drop the legacy column.
3. Replace ad hoc admin checks with permission checks across services,
   middleware, Filament resources, and panel access. Keep `isAdmin()` only for
   role-identity semantics such as last-admin guards and response fields; it
   reads from `user_roles`.
4. Keep role assignment in the user admin UI. Remove/defer runtime
   role-permission management surfaces. Managers may view admin user records,
   but manager user-management mutation workflows are restricted to non-admin
   user records.
5. Test the enums, user-role event flow, manager allow-list, admin-only
   denials, and affected Filament workflows.

## Amendments

### 2026-06-21 — `groups.manage` permission (#48)

The original migration left group administration gated by the `isAdmin()` shim
rather than a permission, because no group-scoped capability existed in the
enum model. While building the operations-oriented Filament navigation (#48) - in
particular a compartment-centric access screen with a "Groups" access tab - this
gap became a concrete inconsistency.

Added a `groups.manage` permission to the enum model and replaced the remaining
group-admin `isAdmin()` checks with `can(Permission::GroupsManage->value)` in:
`GroupResource::canAccess`, its `MembersRelationManager` and
`CompartmentAccessesRelationManager`, the
`CompartmentResource\RelationManagers\GroupAccessesRelationManager`, and the
domain-layer gate `GroupAccessService::ensureCanManageAccess()`.

`groups.manage` is not in the `manager` static binding, so behaviour is
unchanged (group administration stays admin-only). Admins hold it via the
super-role bypass, so no role-permission seed or data migration is required.

`isAdmin()` is intentionally retained where it expresses role identity rather
than a capability (the last-admin guard, the `is_admin` API field, "is the
edited user an admin"), and for admin-only super-user bypasses in compartment
open/edit, where converting to a shared permission would change who is allowed.

### 2026-07-11 — Remove `is_admin_since` as admin source of truth

The transitional dual-write period ended. Effective admin status, super-role
permission bypass, last-admin guards, delete guards, admin status broadcasts,
and Filament user-management boundaries now use event-sourced `user_roles` /
`Role::Admin`.

`users.is_admin_since` is backfilled into `user_roles` by
`2026_07_11_000001_backfill_admin_roles_and_drop_is_admin_since` and then
dropped. Normal seeders are not part of the production migration path.

Manager access to `UserResource` is deliberately narrower than the
`users.manage` permission name alone might imply: managers may manage regular
users and view admin user records, but admin user records are blocked by
record-level edit/delete checks, read-only edit-page rendering, save guards,
bulk delete guards, and header/relation actions. Role/admin actions remain
visible only to actors with `roles.manage`.

## Supersedes / Superseded By

- Supersedes: none (first authorization-model ADR; extends the binary
  `is_admin_since` flag).
- Superseded by: none

## References

- Related issues: #95 (this decision); builds toward #46 (group access,
  ADR-0020), complements #48 (Filament navigation separation); relates to #55
  (user identification), #94 (door-open semantics).
- Related ADRs: ADR-0020 (group-based compartment access - `authorizationType`
  precedence, admin-only management it defers to #95), ADR-0019 (user fields).
- Related code: `app/Models/User.php`, `app/Models/Concerns/HasPermissions.php`,
  `app/Enums/Permission.php`, `app/Enums/Role.php`,
  `app/Providers/AuthorizationServiceProvider.php`,
  `app/Filament/Resources/UserResource`, `config/event-sourcing.php`.
- Mechanism: Laravel Gate (`Gate::before`, `can`, `authorize`, `can:`
  middleware). No external authorization package.
