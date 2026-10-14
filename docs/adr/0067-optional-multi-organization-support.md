# ADR-0067: Optional multi-organization support with shared-schema ownership

## Status

Proposed

## Date

2026-10-14

## Context

Open-Locker assumes one operator per installation. #238 asks for several
organizations on one installation, each managing its own locker banks, users,
groups, and access, without seeing each other's data.

PR #309 implements this in one change (138 files, ~7,000 lines) and reserves
ADR-0065. It is too large to review with confidence and is used only as a
checklist of cases. This ADR records the decisions for an incremental rollout;
#238 tracks the delivery slices.

Constraints from the current code:

- Groups, accesses, open requests, roles, and terms are event-sourced read
  models. Projectors are synchronous and run inside admin-panel requests.
- Locker banks and compartments are plain rows that projectors only update.
- `Gate::before` treats `admin` as a global super-role (ADR-0022), and about
  40 permission checks pass no resource.
- Tests run on SQLite; development and production run PostgreSQL.
- Self-hosted single-operator installations must keep working without any
  extra steps.

## Decision

### Ownership model

1. **Shared schema.** One database; owned rows carry an explicit
   `organization_id`.
2. **One code path.** Every installation has a default organization that owns
   all existing data. Single- and multi-organization mode use the same
   ownership and authorization code.
3. **Composite foreign keys** enforce same-organization relations:
   compartment to locker bank, group grants to group and compartment, and
   direct grants and group memberships to the organization membership
   `(user_id, organization_id)`. A test proves the constraint fires on SQLite;
   a PostgreSQL CI job is the fallback if SQLite cannot express it.
4. **Membership is required.** Users get compartments only from organizations
   they belong to. Removing a member revokes their grants and group
   memberships in that organization through events.
5. **Locker banks are owned from provisioning.** `LockerProvisioningTokenIssued`
   carries the organization; it is fixed afterwards. Moving banks between
   organizations is out of scope, and a reset keeps the organization.

### Event sourcing

6. **Memberships are event-sourced** through an organization membership
   aggregate. The migration backfills existing users through the aggregate.
7. **Events carry the organization.** Each slice that adds `organization_id`
   to a projected table adds an optional `organizationId` to the matching
   events. Projectors map a missing value to the default organization.
8. **Projectors never read ambient state**: no organization context, no
   Filament tenant, no model global scopes. A replay must produce the same
   read models as the live run.

### Authorization

9. **Roles are stored per organization.** A global `platform_admin` role holds
   installation-scoped rights.
10. **Every permission declares a scope.** `system.configure` and `audit.view`
    are installation-scoped; all other permissions are organization-scoped.
    `Gate::before` short-circuits only for `platform_admin`. An organization
    `admin` holds every organization-scoped permission in their own
    organization.
11. **Organization resolution** for a check: the organization of the resource
    when one is passed, otherwise `OrganizationContext`, otherwise deny.
    `OrganizationContext` is set only by the admin panel and CLI commands;
    API requests never have one.
12. **All role reads go through one service** (`OrganizationRoles`). This
    covers recipient lists for help requests, deviation alerts, and status
    broadcasts. An architecture test fails on direct `UserRole` queries
    elsewhere.
13. **Last-admin protection** keeps at least one `admin` per organization
    (lock per organization) and at least one `platform_admin` per
    installation (global lock).

### Mobile API

14. **The mobile API stays organization-agnostic**: no organization header, no
    switcher. A user sees the compartments assigned to them, directly or
    through a group, in all organizations they belong to. An organization
    admin also sees all compartments of their organizations. `platform_admin`
    has no mobile shortcut.
15. **`is_admin` is removed from `UserResource`.** No mobile code reads it.
    This is the only API contract change and requires one client
    regeneration.

### Admin panel

16. **Filament native tenancy** provides the switcher, tenant URLs, and
    `Filament::getTenant()`. It is always enabled, so there is one URL scheme.
    Automatic scoping is disabled (`scopeToTenant(false)`) because its global
    scope and `creating` observer would also apply to synchronous projectors.
17. **Explicit scoping.** Each resource scopes `getEloquentQuery()` and its
    relation pickers to the tenant. Middleware copies the tenant into
    `OrganizationContext`. A coverage test fails for unscoped resources of
    organization-owned models; another organization's record returns 404.
18. **The audit log** is visible to `platform_admin` only.

### User creation

19. **New users join the organization from `OrganizationContext`**;
    `CreateFirstAdmin` uses the default organization. Without a context,
    creation fails.
20. **Creating an existing email** adds that user to the organization. The
    existing profile is never changed, and the admin sees the same success
    notification. Organization admins may edit a user's profile only if the
    user belongs to no other organization.

### Mode switch

21. **Multi-organization mode** (`organizations.multi_organization`, default
    `false`) controls organization management and the switcher. It is
    one-way: with more than one organization, turning it off is rejected at
    boot.

Terms stay installation-wide. MQTT topics, identities, and `locker-client`
are unchanged; inbound handlers derive the organization from the locker bank.

## Alternatives Considered

### Alternative A: database or schema per organization

- Pros: strongest isolation
- Cons: per-tenant migrations, connection routing, and event stores;
  cross-organization users impossible
- Why not chosen: too heavy for self-hosted installations; #238 needs users
  in several organizations

### Alternative B: one large change (PR #309)

- Pros: complete feature at once
- Cons: not reviewable with confidence
- Why not chosen: risk of undetected isolation gaps

### Alternative C: organization context in the mobile app

- Pros: explicit context for every request
- Cons: header, switcher, client changes; users must know organizations
- Why not chosen: ownership can always be derived from the resource

### Alternative D: Filament tenancy with automatic scoping

- Pros: less code per resource
- Cons: global scopes and creation observers apply to synchronous projectors,
  so live runs and replays diverge
- Why not chosen: event-sourcing integrity takes priority

### Alternative E: default-organization admins hold installation rights

- Pros: no new role
- Cons: makes the default organization a hidden super-tenant
- Why not chosen: breaks the single code path

### Alternative F: per-organization audit log

- Pros: organization admins see their own history
- Cons: needs an organization mapping for every audited event, including
  historical ones
- Why not chosen: no current need; deferred to a follow-up

### Alternative G: plain membership pivot table

- Pros: simpler slice
- Cons: not audited; roles could exist without membership
- Why not chosen: membership decides authorization and must be auditable

## Consequences

### Positive

- Single-organization installations keep their behavior; existing admins
  become `admin` of the default organization and `platform_admin`.
- Isolation is enforced by the database, not only by services.
- Replays stay deterministic.
- Each slice can be reviewed and merged on its own.

### Negative

- Admin panel URLs change to include the tenant, also in single-organization
  mode.
- Every Filament resource needs explicit scoping.
- An admin creating an existing email can tell from the shown name that the
  account existed (low-severity enumeration).
- Organization admins have no audit log.
- Locker banks cannot move between organizations.

### Risks

- A role query or Filament resource that skips organization scoping leaks
  data. Mitigation: the `OrganizationRoles` architecture test and the
  resource coverage test.
- SQLite may not enforce composite foreign keys added by table changes.
  Mitigation: constraint tests, with a PostgreSQL CI job as fallback.
- Old mobile app versions keep working because no app code reads `is_admin`.

## Rollout / Migration

Slices land on `main` one PR at a time, as tracked in #238. Until organization
management lands, users and admins see no behavior change, except for the
`is_admin` removal and the new admin panel URLs.

Migrations create the default organization, backfill memberships and roles
through aggregates, backfill `organization_id` on owned rows, and then add
non-null composite foreign keys. Historical events stay unchanged; projectors
map them to the default organization.

To enable multi-organization mode, a platform admin sets the flag and creates
organizations. The switch cannot be reverted once a second organization exists.

## Supersedes / Superseded By

- Supersedes: ADR-0022 in part (`admin` as a global super-role in
  `Gate::before`)
- Superseded by: none

## References

- #238 (multi-organization support), PR #309 (reference implementation)
- #323 (email-only user invitation, follow-up)
- ADR-0022 (role-based access control), ADR-0030 and ADR-0066 (audit log)
