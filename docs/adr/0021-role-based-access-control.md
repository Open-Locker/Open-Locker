# ADR-0021: Capability-based access control — static YAML catalog, event-sourced bindings, hand-rolled on Laravel Gate

## Status

Proposed

## Date

2026-06-17

## Context

Authorization today is a **single binary flag**. `users.is_admin_since` (a timestamp)
backs `User::isAdmin()`, and that one predicate is checked **ad hoc at ~12 call sites**:

- `User::canAccessPanel()` — Filament panel access (`isAdmin()` only).
- `AdminMiddleware` — API guard (`isAdmin()` only).
- `CompartmentAccessService::ensureCanManageAccess()` — grant/revoke compartment access (admins only).
- `CompartmentAccessService::requestOpen()` — sets `authorizationType` (`'admin_override'` for admins;
  `'granted_access'` / `'group_access'` for users, per ADR-0020).
- `GroupAccessService::ensureCanManage…()` — group management (admins only).
- Filament resources / relation managers (`UserResource`, `GroupResource`, `EditUser`) — assume every
  panel user is a full admin.

There are **no roles, no permissions, no Policies, and no Gates** beyond this flag. The model is strictly
admin / not-admin.

#95 requires a **`manager` role** for day-to-day operations: a manager may manage compartment access and
regular users across all locker banks, and open any compartment, but must **not** grant elevated roles,
change locker-bank technical config (Modbus `slave_id`/`address`, provisioning, heartbeat), or touch
legal/system configuration. Admin must remain a strict superset of manager.

The core problem is structural, not "add one more role": **binary role-identity checks do not compose.**
Adding `manager` means rewriting every call site with a different bespoke boolean, with N capabilities × M
roles of scattered logic. Missing one site is a silent privilege hole, and there is nothing central to test.

**Team decision shaping this ADR** (refines an earlier proposed draft that used `spatie/laravel-permission`
with a statically-seeded role→permission map):

- The **catalog** — *which roles and which permissions exist* — is **static, developer-owned**, not
  user-editable. Rationale: permissions are strings the code checks; letting non-developers delete a
  permission that the code references would cause fatal errors / corruption. The catalog is therefore
  managed in a version-controlled YAML file, changed only via a code change + review.
- The **bindings** — *which permissions a role currently has*, and *which roles a user has* — are
  **dynamic**, stored in the database, editable by admins at runtime ("today a manager can do X, tomorrow
  not"), and **event-sourced** so every change is auditable (who, what, when).

Constraints:
- Role/permission **binding** changes must be event-sourced with **actor/audit** — #95.
- The existing **first-user-becomes-admin bootstrap** must be preserved and made auditable.
- Must integrate consistently across Filament, API middleware, services, resources, and broadcasts.
- Should stay consistent with the existing aggregate → projector → read-model pattern (ADR-0016/0020).

## Decision

Introduce a **capability-based** authorization model: code checks **permissions**, not role identity.
Build it **hand-rolled on Laravel's native Gate** — no `spatie/laravel-permission` (see Alternatives).

**1. Static catalog — developer-owned YAML, read at runtime.**
A version-controlled file (e.g. `config/authorization.yaml`) is the single source of truth for *what
exists*:

```yaml
permissions:
  - panel.access
  - users.manage
  - groups.manage         # manage groups, membership & group compartment access (see Amendments)
  - compartment.access.manage
  - compartment.open
  - roles.manage          # grant/revoke user roles
  - lockerbank.configure  # Modbus slave_id/address, provisioning, heartbeat
  - system.configure      # legal/system resources
roles:
  - user
  - manager
  - admin
default_bindings:          # initial role→permission map; only seeds the dynamic table on fresh install
  manager: [panel.access, users.manage, compartment.access.manage, compartment.open]
  admin:   '*'             # admin is a super-role (see decision 5)
```

It is loaded into a cached config repository at boot. Permission **names referenced by code** are exposed
as constants (a `Permission` enum / constants class) so call sites are type-safe and greppable; a unit test
asserts every code-referenced permission exists in the YAML, so **deleting a still-used permission fails
CI** — directly addressing the safety concern. Users have **no UI** to edit this catalog.

**2. Dynamic bindings — database read models, event-sourced.**
Two aggregates own the auditable bindings; their projectors are the **only writers** of the read-model
tables:

- `UserRoleAggregate` (keyed by user UUID): `UserRoleGranted` / `UserRoleRevoked` → `UserRoleProjector`
  maintains a `user_roles` read model. (target user, role, **actor** user id, timestamp)
- `RoleAggregate` (keyed by role name/UUID): `RolePermissionGranted` / `RolePermissionRevoked` →
  `RoleProjector` maintains a `role_permissions` read model. (role, permission, **actor**, timestamp)

Controllers/Filament **never write these tables directly** — they record an event; the projector applies it.
Binding values are validated against the static catalog at write time (can't bind an unknown permission or
unknown role). On a fresh install the `default_bindings` from YAML seed the initial `role_permissions`
rows (as bootstrap events); after that the DB is authoritative and admins edit it at runtime.

**3. Enforcement — one resolver + one `Gate::before`, then `can()` everywhere.**
A small `HasPermissions` concern on `User` resolves effective permissions ("user's roles → their bound
permissions") and exposes `hasPermission()`, `hasAnyPermission()`, `hasAllPermissions()`, `permissions()`,
`hasRole()`. A single `Gate::before` maps every `can('…')` to `hasPermission('…')`. We therefore reuse
Laravel's entire authorization API unchanged — `$user->can()`, `authorize()`, `@can`, and
`->middleware('can:…')` — and only write the resolver. Every ad-hoc `isAdmin()` site becomes a permission
check:

```php
$user->can('compartment.access.manage')   // service / controller
$this->authorize('compartment.open')       // controller guard
@can('users.manage')                        // Filament / Blade
->middleware('can:panel.access')            // routes
```

**4. Source of truth & audit.** The event stream is the audit record for all binding changes; the
`user_roles` / `role_permissions` tables are rebuildable read models (`event-sourcing:replay`). The static
catalog is *not* event-sourced — it is config, not audited per-actor domain state.

**5. Admin is a super-role.** `Gate::before` short-circuits: `hasRole('admin') ? true : null`. Admin passes
every permission check unconditionally, so #95's "admin is a strict superset / keeps all capabilities"
holds **by construction** and survives runtime permission editing — the dynamic role→permission map only
ever governs `manager` and any future non-admin roles. Guard rails: the `admin` role is system-protected
(cannot be unbound from `roles.manage` in a way that locks out management), and the existing "cannot remove
the last admin" guard is preserved against the `admin` role.

**6. Bootstrap — first user becomes admin, as an auditable event.**
Replace the `User::booted()` `static::created` direct `is_admin_since` write with a system-initiated
`UserRoleGranted(role: admin, actor: system)` on first registration. Behavior is preserved and now
auditable. `isAdmin()` is reimplemented as `hasRole('admin')` (kept as a thin convenience so existing
references compile during migration); existing `is_admin_since` admins are **backfilled** with bootstrap
admin-grant events.

**7. Open authorization type — add `manager_override`.**
`requestOpen()` gains a distinct `authorizationType: 'manager_override'` for manager-initiated opens,
parallel to the existing `'admin_override'` / `'granted_access'` / `'group_access'` (ADR-0020), for audit
clarity rather than overloading `admin_override`.

**8. Management UI — admin-only Filament, event-emitting.**
Two admin-only surfaces (visible only with `roles.manage`): (a) a per-user **role assignment** action on
`UserResource`, and (b) a per-role **permission toggle** screen (roles listed from the static catalog,
permissions shown as a checklist from the static catalog, toggling records `RolePermissionGranted/Revoked`).
Both record events — never a direct table write. The catalog itself is **not** editable in any UI.

## Rationale

Checking permissions instead of role identity makes authorization **compose** and centralizes the
role→capability decision, eliminating the scattered-boolean problem. Splitting **static catalog** (YAML,
dev-owned) from **dynamic bindings** (DB, event-sourced) matches the team's intent exactly: developers
guarantee the set of capabilities the code relies on (safety), while admins tune *who can do what* at
runtime with a full audit trail.

We hand-roll on Laravel's Gate rather than adopt `spatie/laravel-permission` because spatie is
**DB-catalog-based**: roles and permissions must be DB rows, which would create a **second source of truth**
beside the YAML and require a sync command to reconcile them. Since the dynamic bindings must be
event-sourced anyway (bypassing spatie's assignment APIs), spatie would be reduced to a pivot table plus a
`Gate::before` we can write ourselves in a few lines — while still imposing its migrations, caching config,
sync step, and runtime CRUD we deliberately don't want. For a 3-role / ~7-permission system the hand-rolled
resolver is *less* total moving parts, keeps YAML as the single catalog source of truth, and leaves the
check side as idiomatic Laravel.

## Alternatives Considered

### Alternative A: `spatie/laravel-permission` as the engine

- Pros: mature, free (MIT); prebuilt `hasPermissionTo()/assignRole()` helpers; native Gate integration;
  permission caching out of the box.
- Cons: catalog lives in **DB**, creating a second source of truth vs the YAML and requiring a
  `permissions:sync` step; encourages runtime CRUD of roles/permissions we explicitly want to forbid; its
  assignment APIs write tables directly, so the required event-sourced seam bypasses them anyway — leaving
  spatie as a glorified pivot plus a `Gate::before`.
- Why not chosen: pays a dependency + dual-source + sync cost for helpers we can write in ~50–80 lines,
  while undermining "YAML is the single source of truth." Worth revisiting only if the role/permission set
  grows large or self-service permission editing becomes a real need.

### Alternative B: Statically-seeded role→permission map (the earlier draft of this ADR)

- Pros: map is version-controlled and identical across environments; no dynamic-binding tables.
- Cons: changing what a manager may do requires a deploy; the team explicitly wants **runtime, audited**
  binding changes ("today a manager can do X, tomorrow not").
- Why not chosen: contradicts the team's dynamic-bindings decision. (This ADR supersedes that earlier
  proposed direction before acceptance.)

### Alternative C: Adopt spatie *and* assign directly (no event sourcing)

- Pros: simplest possible integration.
- Cons: violates #95's "binding changes must be event-sourced / auditable"; no actor trail; inconsistent
  with the rest of the domain (ADR-0016/0020).
- Why not chosen: contradicts a hard requirement.

### Alternative D: Role-identity checks (`hasAnyRole(['admin','manager'])`) instead of permissions

- Pros: slightly less upfront setup (no permission catalog).
- Cons: scatters the role→capability mapping across call sites — the exact problem we set out to fix; every
  new role re-touches every site.
- Why not chosen: defeats the purpose; permissions are the indirection that makes this maintainable.

### Alternative E: `bezhanSalleh/filament-shield`

- Pros: ready-made Filament UI for roles/permissions; auto-generates CRUD-shaped permissions.
- Cons: builds on spatie (inherits Alternative A's issues); its assignment UI writes tables directly
  (bypasses the event store); generated permissions are CRUD-shaped and miss our operational ones; more
  concepts than a 3-role system warrants.
- Why not chosen: conflicts with the event-sourced + YAML-catalog model; we need a thin event-emitting
  action regardless.

## Consequences

### Positive

- Authorization composes: code asks "can you do X?"; the catalog lives in one YAML file; a future role is a
  YAML edit + binding it via the UI.
- **Single source of truth for the catalog** (YAML, dev-owned, reviewed) — no drift, no sync step, and
  removing a still-used permission fails CI.
- Binding changes (role↔permission, user↔role) are fully event-sourced and auditable (actor + timestamp),
  like the rest of the domain; rebuildable by replay.
- **No new dependency**; native Gate means Filament, `authorize()`, middleware, and Blade all work via one
  `Gate::before`.
- The ~12 ad-hoc `isAdmin()` checks collapse into a small, testable set of permission checks; admin
  super-role keeps "admin = superset" guaranteed even under runtime editing.

### Negative

- We own ~50–80 lines of resolver/catalog code (the `HasPermissions` concern, YAML loader, `Gate::before`)
  and the permission-name constants/test — versus spatie's prebuilt helpers.
- Two binding read-model tables + projectors + aggregates (the bindings would need event sourcing under any
  option, but the read models and resolver are ours to maintain).
- We manage our own permission resolution caching (cache effective permissions per user/request and bust on
  binding-change events).

### Risks

- **Projection drift** (events vs `user_roles`/`role_permissions`). Mitigation: projectors are the sole
  writers; tables rebuildable by replay; cover grant/revoke/bootstrap with feature tests.
- **Catalog/binding mismatch** — a binding referencing a permission later removed from YAML. Mitigation:
  validate bindings against the catalog at write time; ignore/log unknown permissions at read time; the
  CI test prevents removing a code-referenced permission.
- **Privilege gap during migration** — a missed call site could over- or under-authorize. Mitigation:
  migrate behind permissions exhaustively; add tests asserting the manager allow-list and admin-only
  denials before removing `isAdmin()` shims.
- **Cache staleness** — resolved permissions must be invalidated when a binding event lands. Mitigation:
  bust the per-user permission cache in the projector.

## Rollout / Migration

Deliver in reviewable slices after this ADR is accepted:

1. **Catalog + enforcement core** — `config/authorization.yaml` (permissions, roles, default bindings),
   a cached loader, `Permission` constants + the catalog-coverage test, the `HasPermissions` concern, and
   the `Gate::before` (incl. admin super-role). No behavior change yet (the `isAdmin()` shim stands).
2. **Event-sourced bindings** — `UserRoleAggregate` + `RoleAggregate`, their events and projectors
   (`user_roles`, `role_permissions` migrations), registered in `config/event-sourcing.php`; seed initial
   `role_permissions` from `default_bindings`; convert the first-user bootstrap to a system
   `UserRoleGranted(admin)` event; backfill existing `is_admin_since` admins. Reimplement `isAdmin()` as
   `hasRole('admin')`; keep the last-admin guard.
3. **Enforcement migration** — replace all `isAdmin()` call sites (services, `AdminMiddleware`,
   `canAccessPanel`, Filament resources/relation managers) with permission checks; add `manager_override`
   to `requestOpen()`; scope Filament resources/actions so managers see only what they may manage.
4. **Management UI** — admin-only Filament: per-user role assignment + per-role permission toggles, both
   routed through the aggregates (never a direct table write). No UI for the catalog.
5. **Tests** — manager allow-list, admin-only denials (roles, locker config, Modbus mapping, system
   resources), event-sourced binding + replay, bootstrap-still-creates-admin, and the catalog-coverage test.

Fallback: until slice 3 lands, `isAdmin()` shims keep current behavior, so slices 1–2 are non-breaking.

## Amendments

### 2026-06-21 — `groups.manage` permission (#48)

The original migration (slice 3) left **group administration** gated by the
`isAdmin()` shim rather than a permission, because no group-scoped capability existed
in the catalog. While building the operations-oriented Filament navigation (#48) — in
particular a compartment-centric access screen with a "Groups" access tab — this gap
became a concrete inconsistency.

Added a `groups.manage` permission to the catalog and replaced the remaining
group-admin `isAdmin()` checks with `can(Permission::GroupsManage->value)` in:
`GroupResource::canAccess`, its `MembersRelationManager` and
`CompartmentAccessesRelationManager`, the new
`CompartmentResource\RelationManagers\GroupAccessesRelationManager`, and the
domain-layer gate `GroupAccessService::ensureCanManageAccess()`.

`groups.manage` is **not** in the `manager` default binding, so behaviour is unchanged
(group administration stays admin-only) — admins hold it via the super-role expansion
(decision 5), so no re-seed or data migration is required.

`isAdmin()` is intentionally retained where it expresses **role identity** rather than
a capability (the last-admin guard, the `is_admin` API field, "is the *edited* user an
admin"), and for admin-only super-user **bypasses** in compartment open/edit, where
converting to a shared permission would change *who* is allowed.

### 2026-06-23 — Soft-revoke role→permission bindings + audit trail (#95)

The original `RoleProjector` **deleted** the `role_permissions` row on
`RolePermissionRevoked`, so a revoked binding left no trace. Building the
admin role-permission management screen (per-role grant/revoke, mirroring the
compartment-access screens) required showing *when/by whom* a permission was
granted **and** revoked.

Changed the `role_permissions` read model to **soft-revoke**: revocation now
sets `revoked_at` / `revoked_by_user_id` and keeps the row (new migration adds
both columns), instead of deleting it. **Active = `revoked_at IS NULL`**, so
`HasPermissions::permissionNames()` now filters `whereNull('revoked_at')`. A
re-grant clears the revoke audit and reactivates the row (`updateOrCreate`).

This is a **read-model / projector change only** — no new events, no aggregate
or stored-event changes, so the event log is untouched and the table remains
rebuildable by replay. It refines decision 8's "permission toggle" surface into
a grant/revoke action table with a granted/revoked audit.

## Supersedes / Superseded By

- Supersedes: none (first authorization-model ADR; extends the binary `is_admin_since` flag). Revises an
  earlier proposed draft of this same ADR that used spatie + a statically-seeded role→permission map.
- Superseded by: none

## References

- Related issues: #95 (this decision); builds toward #46 (group access, ADR-0020), complements #48
  (Filament navigation separation); relates to #55 (user identification), #94 (door-open semantics).
- Related ADRs: ADR-0020 (group-based compartment access — `authorizationType` precedence, admin-only
  management it defers to #95), ADR-0019 (user fields).
- Related code: `app/Models/User.php` (`isAdmin()`, `canAccessPanel()`, `booted()` bootstrap),
  `app/Http/Middleware/AdminMiddleware.php`, `app/Services/CompartmentAccessService.php`,
  `app/Services/GroupAccessService.php`, `app/Filament/Resources/UserResource`,
  `app/Filament/Resources/GroupResource`, `config/event-sourcing.php`.
- Mechanism: Laravel Gate (`Gate::before`, `can`, `authorize`, `can:` middleware). No external
  authorization package.
