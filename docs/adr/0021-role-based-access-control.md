# ADR-0021: Capability-based access control with an event-sourced role model

## Status

Proposed

## Date

2026-06-13

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

There are **no roles, no permissions, no Policies, and no Gates**. The model is strictly admin / not-admin.
`spatie/laravel-permission` is **not installed** (it appears in `composer.lock` only as a `suggest` of
`dedoc/scramble`).

#95 requires a **`manager` role** for day-to-day operations: a manager may manage compartment access and
regular users across all locker banks, and open any compartment, but must **not** grant elevated roles,
change locker-bank technical config (Modbus `slave_id`/`address`, provisioning, heartbeat), or touch
legal/system configuration. Admin must remain a strict superset of manager.

The core problem is structural, not "add one more role": **binary role-identity checks do not compose.**
Adding `manager` means rewriting every call site with a different bespoke boolean (`isAdmin() ||
isManager()` here, `isAdmin()`-only there), with N capabilities × M roles of scattered logic. Missing one
site is a silent privilege hole, and there is nothing central to test.

Constraints:
- Role changes (grant/revoke) **must be event-sourced** with **actor/audit** (who, what, when) — #95.
- The existing **first-user-becomes-admin bootstrap** must be preserved and made auditable.
- Must integrate consistently across Filament, API middleware, services, resources, and broadcasts.
- Should stay consistent with the existing aggregate → projector → read-model pattern (ADR-0016/0020).

## Decision

Introduce a **capability-based** authorization model: code checks **permissions**, not role identity.
Roles are named bundles of permissions; users hold roles; role **assignment** is **event-sourced**.

**1. Enforcement layer — `spatie/laravel-permission`, checked as permissions.**
Adopt `spatie/laravel-permission` as the role/permission engine and Gate integration. Every ad-hoc
`isAdmin()` call site is replaced with a **permission** check, never a role-identity check:

```php
$user->can('compartment.access.manage')   // service / controller
$this->authorize('compartment.open')       // controller guard
@can('users.manage')                        // Filament / Blade
->middleware('permission:panel.access')     // routes
```

**2. Permission catalog and role→permission map — static config, seeded in code.**
These are system configuration (not runtime data), version-controlled and identical across environments,
defined in a seeder:

| Permission | user | manager | admin |
|---|---|---|---|
| `panel.access` | — | ✓ | ✓ |
| `users.manage` | — | ✓ | ✓ |
| `compartment.access.manage` | — | ✓ | ✓ |
| `compartment.open` | — | ✓ | ✓ |
| `roles.manage` (grant/revoke roles) | — | — | ✓ |
| `lockerbank.configure` (Modbus `slave_id`/`address`, provisioning, heartbeat) | — | — | ✓ |
| `system.configure` (legal/system resources) | — | — | ✓ |

`admin` is a strict superset of `manager`. Regular `user` holds none (no panel access). The map lives in
**exactly one place**; adding a future role = edit the seeder, not the call sites.

**3. Source of truth — event-sourced role assignment.**
A new `UserRoleAggregate` (keyed by user UUID) records `UserRoleGranted` and `UserRoleRevoked`, each
carrying the **target user, role, actor (granter) user id, and timestamp**. A `UserRoleProjector`
reacts by maintaining spatie's assignment tables (`$user->assignRole()` / `removeRole()`).
**Controllers/Filament never call `assignRole()` directly** — they record an event; the projector is the
only writer of the spatie assignment tables. (Permission/role *definitions* are seeded, not event-sourced
— they are static config, not auditable domain state.)

```
UI / API "grant manager"  →  UserRoleAggregate::grantRole()  →  UserRoleGranted (actor, role, at)
                                                                      ↓ UserRoleProjector
                                                                 $user->assignRole('manager')
                                                                      ↓
                              code:  $user->can('compartment.access.manage')   ← spatie, just works
```

**4. Bootstrap — first user becomes admin, as an auditable event.**
Replace the `User::booted()` `static::created` hook's direct `is_admin_since` write with a
**system-initiated `UserRoleGranted(role: admin, actor: system)`** event on first registration. Behavior
is preserved (first user is admin) and now auditable. `isAdmin()` is reimplemented as
`hasRole('admin')` (kept as a thin convenience over the new model so existing references compile during
migration); the admin-deletion guard is preserved against the `admin` role.

**5. Open authorization type — add `manager_override`.**
`requestOpen()` gains a distinct `authorizationType: 'manager_override'` for manager-initiated opens,
parallel to the existing `'admin_override'` / `'granted_access'` / `'group_access'` (ADR-0020), for audit
clarity rather than overloading `admin_override`.

**6. Management UI — a purpose-built, admin-only Filament action; not `filament-shield`.**
Granting/revoking a user's role is exposed as an **admin-only action on the existing `UserResource`**
(visible only with `roles.manage`) that records a `UserRoleGranted` / `UserRoleRevoked` event — never a
direct `assignRole()`. We deliberately do **not** adopt `bezhanSalleh/filament-shield` as the management
UI, for two reasons: (a) its assignment UI writes spatie's tables **directly**, bypassing the event store
and breaking decision 3's audit requirement; and (b) its generator produces **Filament-CRUD-shaped**
permissions (`view_user`, `delete_locker`, …) that only partially overlap our **operational** catalog
(`compartment.open`, `compartment.access.manage`), so it would not remove the need to hand-define those.
Because we must emit events from the action anyway, a thin custom action is simpler than bending shield.
Shield may *optionally* be used later as a one-off scaffold to draft the static-definition seeder (decision
2), but it is **not a runtime dependency** of this decision. The permission/role **definition** screens
(decision 2) are not exposed in the UI at all — they are code-seeded and admin-only by construction.

## Rationale

Checking permissions instead of roles is the whole point: it makes authorization **compose** and
centralizes the role→capability decision, eliminating the scattered-boolean problem. `spatie/laravel-permission`
is the mature, free (MIT) Laravel standard for this, registers as native Gates (so Filament, `authorize()`,
middleware, and Blade all work with zero glue), and gives us the permission entity we'd otherwise hand-build.

Its one mismatch with this repo is that it is **DB-mutation-based** (`assignRole()` writes tables directly),
which conflicts with #95's event-sourcing rule. We resolve that with the established
aggregate → projector pattern: **events are the source of truth and the only audit record; the projector
keeps spatie's tables in sync; the check side is pure spatie.** This confines the seam to one projector and
keeps every read/enforcement path idiomatic. Definitions stay seeded because "what a manager may do" is
static configuration, not an audited per-actor event.

## Alternatives Considered

### Alternative A: Hand-rolled roles + capability map via Laravel Gates (no package)

- Pros: no new dependency; no dual-write seam (a `UserRoleProjector` can own a plain `role` column/table
  natively); minimal surface for exactly 3 roles; fully event-source-native.
- Cons: we re-implement the permission entity, the role→permission map, assignment APIs, and caching that
  spatie already provides; no Filament management UI; more bespoke code to test and maintain as roles grow.
- Why not chosen: the permission-check ergonomics and Gate/Filament integration are exactly what we want,
  and re-building them is avoidable work. Spatie's only real downside (direct writes) is neutralized by the
  projector seam we need anyway. Worth revisiting if the spatie dual-write proves to be more friction than
  the saved code is worth.

### Alternative B: Adopt spatie *and* manage assignment directly (no event sourcing)

- Pros: simplest possible integration — use `assignRole()` from controllers/Filament as documented.
- Cons: violates #95's explicit "role changes must be event-sourced / auditable" requirement; no actor
  trail; inconsistent with the rest of the domain (ADR-0016/0020).
- Why not chosen: directly contradicts a hard requirement.

### Alternative C: Keep role-identity checks (`hasAnyRole(['admin','manager'])`) instead of permissions

- Pros: slightly less upfront setup (no permission catalog).
- Cons: still scatters the role→capability mapping across call sites — the exact problem we set out to
  fix; every new role re-touches every site.
- Why not chosen: defeats the purpose; permissions are the indirection that makes this maintainable.

### Alternative D: `bezhanSalleh/filament-shield` as the role/permission management UI

- Pros: ready-made Filament UI for roles and permissions; auto-generates a permission per Filament
  resource/action; widely used; MIT/free.
- Cons: its assignment UI writes spatie's tables **directly**, bypassing the event store (violates the
  event-sourced/auditable requirement); generated permissions are CRUD-shaped and miss the operational
  ones we need; adds a dependency and more concepts than a 3-role system warrants.
- Why not chosen: the direct-write assignment conflicts with decision 3, and we need a thin event-emitting
  action regardless — which also gives us full control over which managers see which records. Its only
  durable value (scaffolding the definition seeder) is marginal versus writing the seeder by hand. Left as
  an optional, non-binding scaffold; can be revisited if admins later need self-service permission editing.

## Consequences

### Positive

- Authorization composes: code asks "can you do X?", the role→permission map lives in one seeded place,
  and a future role is a seeder edit with **zero call-site changes**.
- Role assignment is fully event-sourced and auditable (actor + timestamp), like the rest of the domain.
- Native Gate integration: Filament, `authorize()`, middleware, and Blade all work unchanged.
- The ~12 ad-hoc `isAdmin()` checks collapse into a small, testable set of permission checks.

### Negative

- New dependency (`spatie/laravel-permission`) plus its tables.
- A **dual-write seam**: the projector must keep spatie's assignment tables in sync with role events
  (rebuildable by replay, but a moving part).
- One-time refactor of every existing `isAdmin()` call site and the Filament resources/pages.

### Risks

- **Projection drift** (events and spatie tables diverge). Mitigation: the projector is the sole writer of
  assignment tables; tables are rebuildable by replaying role events; cover grant/revoke/bootstrap with
  feature tests.
- **Privilege gap during migration** — a missed call site could over- or under-authorize. Mitigation:
  migrate behind permissions exhaustively, add tests asserting manager allow-list and admin-only denials
  before removing `isAdmin()` shims.
- **Permission caching** — spatie caches permissions; seeding/altering definitions requires a cache reset
  in deploy. Mitigation: reset cache in the seeder and document in rollout.

## Rollout / Migration

Deliver in reviewable slices after this ADR is accepted:

1. **Engine + definitions** — `composer require spatie/laravel-permission`, publish migrations, seed the
   permission catalog and role→permission map (table above). No behavior change yet.
2. **Event-sourced assignment** — `UserRoleAggregate`, `UserRoleGranted`/`UserRoleRevoked`,
   `UserRoleProjector` (registered in `config/event-sourcing.php`); convert the first-user bootstrap to a
   system `UserRoleGranted(admin)` event; **backfill** existing `is_admin_since` admins by emitting
   bootstrap admin-grant events (or seeding their `admin` role). Reimplement `isAdmin()` as
   `hasRole('admin')`; keep the admin-deletion guard.
3. **Enforcement migration** — replace all `isAdmin()` call sites (services, `AdminMiddleware`,
   `canAccessPanel`, Filament resources/relation managers) with permission checks; add `manager_override`
   to `requestOpen()`; scope Filament resources/actions so managers see only what they may manage.
4. **Management UI** — admin-only Filament action to grant/revoke roles on a user, routed through
   `UserRoleAggregate` (never direct `assignRole()`).
5. **Tests** — manager allow-list, admin-only denials (roles, locker config, Modbus mapping, system
   resources), event-sourced assignment + replay, and bootstrap-still-creates-admin.

Fallback: until slice 3 lands, `isAdmin()` shims keep current behavior, so slices 1–2 are non-breaking.

## Supersedes / Superseded By

- Supersedes: none (first authorization-model ADR; extends the binary `is_admin_since` flag)
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
- Package: `spatie/laravel-permission` (MIT). Optional UI layer: `bezhanSalleh/filament-shield` (MIT).
