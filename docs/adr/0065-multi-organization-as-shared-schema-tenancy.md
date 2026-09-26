# ADR-0065: Multi-organization support as shared-schema tenancy

## Status

Proposed

## Date

2026-09-17

## Context

Open Locker treats one deployment as one operator organization. Users, roles,
groups, locker banks, terms and audit data are installation-global; `admin` and
`manager` apply across the whole installation; event streams carry no
organization context; Filament tenancy is not enabled; and API tokens carry no
active organization. MQTT devices are isolated per locker-bank UUID but are not
grouped by operator.

Issue #238 asks whether an optional managed service should host several isolated
organizations on one backend, and whether that belongs in the open core at all.
The decision has to hold against `VISION.md`: affordability over feature breadth,
trust as the foundation, data sovereignty for self-hosters, one stable core, and
a managed service that can fund the project.

Two findings from surveying the code shaped this ADR:

1. **Authorization already has a choke point.** Every capability check resolves
   through `HasPermissions::roleNames()` (reading the event-sourced `user_roles`
   read model) and `Gate::before`. There are 42 call sites across 22 files
   (`isAdmin()` 12, `can(Permission::…)` 30), but they do not each need editing:
   making the two seams organization-aware makes all 42 organization-aware.
2. **Roles are event-sourced.** `UserRoleGranted` / `UserRoleRevoked` project into
   `user_roles`. Scoping roles therefore changes domain events, not just a table,
   and historical events carry no organization to replay into.

## Decision

Adopt **shared-schema tenancy in a single deployment**, with ownership always
present and the multi-organization UI gated behind an optional mode.

1. **One installation, one database, explicit organization ownership.** Not one
   deployment per operator, and not a database per operator.
2. **Ownership is always on.** A fresh or upgraded installation gets exactly one
   automatically created default organization. Single-organization installs never
   see the concept; there is one ownership and authorization path in both modes,
   not two implementations.
3. **The optional mode gates UI and workflows only** — organization creation,
   membership management, the organization switcher and platform administration.
   It never changes the data model or the authorization path.
4. **Membership is a relation, not a column.** `organization_user` records that a
   person belongs to an operator. Single-organization installs simply hold one
   membership per user. Membership is recorded separately from any role, so an
   ordinary end user (who holds no role row) is still a member, and revoking a
   role does not silently remove someone from the organization.
5. **Roles live on the membership.** Issue #238 requires "organization-scoped
   `user`, `manager`, and `admin` roles"; carrying that on the existing
   `user_roles` table via an `organization_id` column is this ADR's choice rather
   than the issue's, because that table already exists and is already
   event-sourced. Holding the role on the `organization_user` pivot instead would
   satisfy #238 equally. Either way, the same person can be a manager for one
   operator and an ordinary user for another.
6. **`manager` and `user` are never shown that other organizations exist.** This
   does not come from #238; it is decided here. Those roles act inside exactly
   one organization and the concept stays invisible to them, which is also what
   keeps single-organization installations unchanged.
7. **`platform_admin` is a separate, non-membership role.** It administers the
   installation — creating organizations, provisioning hardware, appointing the
   first organization admin — and is the only role whose `organization_id` is
   null. Privilege comes from holding the role, never from the null itself, so a
   row that loses its organization by accident yields a member of nothing rather
   than a superuser.
8. **Organization-awareness lands on the two seams**, not on 42 call sites:
   `roleNames()` resolves roles within the active organization, and `Gate::before`
   short-circuits on `platform_admin` instead of installation-wide `admin`.
9. **Isolation is enforced twice.** A global scope on organization-owned models is
   what we write; database constraints are what save us when someone forgets.
   Concretely, answering #238's question about which constraints must hold
   independently of application scopes:
   - `organization_id` is `NOT NULL` on every organization-owned table once the
     backfill has run, so an unscoped insert fails rather than creating an
     orphan that every scope ignores.
   - Child rows carry their parent's organization and are tied to it by a
     **composite foreign key** — `compartments (locker_bank_id, organization_id)`
     against `locker_banks (id, organization_id)`, and the same shape for access
     grants and group membership. This is the constraint that matters: it makes a
     cross-organization reference physically unwritable, rather than merely
     unqueried.
   - Relation pickers count as an isolation surface, not a UI detail: an
     unscoped dropdown will happily offer another operator's users or
     compartments, which leaks through the panel rather than through the API.
   - Uniqueness that is per-operator rather than global includes
     `organization_id` in the key; locker UUIDs and MQTT identities deliberately
     do not, because they stay globally unique.
   - A check constraint on `user_roles` ties the two role kinds together:
     `organization_id IS NULL` if and only if the role is `platform_admin`.
   Route model binding and direct service calls must fail closed across the
   boundary, and broadcast channel authorization must verify organization
   ownership — a compartment UUID is guessable, and REST refusing while a socket
   allows is the likeliest way this leaks.
10. **Historical events belong to the default organization, permanently.** New
    domain events carry organization context; existing events are replayed into
    the default organization. Creating a second organization never reassigns
    history, and moving a locker bank between organizations afterwards is not
    supported.
11. **The mobile app acts within one organization at a time.** A user who belongs
    to several never sees them together: the app holds an active organization,
    the API carries it, and switching is explicit. This answers #238's question
    about how the mobile API selects an organization, and it keeps
    `/terms/current` a single document — the active organization's — so the terms
    gate introduced in ADR-0060 needs no change. The client cost is smaller than
    it first appears: `mobile-app/src/store/baseApi.ts` already attaches the auth
    token in `prepareHeaders`, so the organization header goes in that same one
    place and no generated endpoint changes. Only the new endpoint listing a
    user's organizations — what the switcher displays — requires regenerating the
    client.
    The switcher sits on the home screen above the locker bank chips. So that
    switching is instant, the app loads every organization's lockers up front,
    one request per organization naming its own organization in the header,
    which `prepareHeaders` then leaves alone. It still shows one organization
    at a time; switching changes which cached list is shown and re-reads only
    the profile and terms, which answer for the active organization.
12. **Every role sees exactly one organization at a time, and switching is
    explicit.** This holds in Filament as well as on mobile, for `platform_admin`
    as much as for `user`: there is no combined view of several operators
    anywhere in the product. One mechanism — the active organization — serves all
    three surfaces instead of three designs.
13. **A platform admin may enter an organization, and entering it is recorded.**
    Managing operators is the job; reading their users, access grants and locker
    contents is a different thing, and for a managed service it is the trust
    boundary `VISION.md` names. Because decision 12 makes seeing an organization
    require an explicit switch, the switch is the natural audit point: a
    `platform_admin` entering an organization they are not a member of records an
    event in the existing audit log. The record is shown only to platform
    admins: a platform admin belongs to no organization, and organization admins
    see only what happens inside theirs, so events about platform admins (their
    entering, and their role being granted or revoked) are not the operator's.
    Platform admins can therefore see afterwards which of them entered which
    organization and when. This is chosen deliberately over "never" and over "on
    request", both of which cost more and neither of which support routine
    operational support.
14. **The active organization travels in a request header, not in the token.**
    Switching must not log anyone out, so it cannot re-issue the Sanctum token.
    The client states which organization it is acting in and the server decides
    what that statement is allowed to mean, on every request:
    - Header present: the user must be a member, otherwise the request is
      refused. The header is never trusted on its own.
    - Header absent and the user has exactly one membership: use it. Every
      existing mobile client sends no header, so this keeps them all working
      unchanged and keeps single-organization installations unaware the feature
      exists.
    - Header absent and the user has several: start them in the first by name.
      Refusing was the original decision here, on the grounds that the server
      should not guess — but there is nothing to guess *about*. Every
      organization on offer is one this person already belongs to, so any of
      them is a legitimate place to begin, and refusing turned signing in into a
      decision taken before the app had shown anything. Choosing a different one
      is a switch inside the app, not a gate in front of it.
15. **Filament's native tenancy drives the panel, reading the shared context.**
    Filament 5.3 ships panel tenancy with a switcher, scoped resources and
    tenant-aware relations, and asks only for a tenant model plus `getTenants()`
    and `canAccessTenant()` on the user — which `organization_user` already
    satisfies. It is not an alternative to a framework-independent organization
    context: the API, event store, projectors, reactors and queued work need that
    context regardless, so Filament reads it rather than owning it. Two
    consequences follow. Panel routes gain the tenant (`/admin/{tenant}/…`), so
    existing bookmarks break once, and `getTenants()` answers differently for
    `platform_admin` — every organization, rather than their memberships, since
    they are deliberately a member of none.
16. **Being offered an organization means being able to work in it.**
    Membership alone is not enough for the panel: an ordinary end user belongs
    to an operator and holds no role there, so switching into it would land them
    in a panel with every resource hidden and nothing for the post-login
    redirect to reach. The panel offers, and admits to, only organizations where
    the person holds a role carrying panel access — and a platform admin reaches
    all of them. Whether someone may enter the panel *at all* is asked during
    authentication, before any tenant exists, so it is answered across every
    organization they belong to rather than the current one.
17. **One panel, not two.** `platform_admin` can do everything an organization
    admin can do, plus create, read and update organizations, and must
    switch into an organization to see its data like anyone else. A separate
    platform panel would therefore duplicate every resource to add one, so
    organization management is simply a resource visible only to
    `platform_admin`. Stated plainly, because customers should not have to infer
    it: under this model a platform admin has full read *and* write inside any
    organization, and the audit trail that decision 13 creates is visible to
    platform admins only. The operator is trusting the host, not checking it.
    Deleting an organization is deliberately excluded. One owns locker banks,
    compartments, grants, terms and an immutable event history, and there is no
    safe default for what becomes of those — the database already refuses to
    drop an organization still owning a locker bank. Removing an operator is an
    operational procedure, not a row action.
18. **Terms, acceptance state and notifications belong to one organization.**
    Each operator publishes its own terms, and acceptance is recorded per
    organization — a user who belongs to two accepts two documents and may have
    accepted one and not the other. Because decision 11 makes the app act within
    one organization at a time, the gate stays simple: `/terms/current` returns
    the active organization's document, and the refusal introduced in ADR-0060
    fires on the active organization's acceptance. Notifications are likewise
    per-organization and are never merged across them, so a manager for two
    operators is told which one an alert concerns.
19. **The organization is always passed in, never discovered.** Decision 14
    carries it on the request, but reactors, queued notifications, scheduled jobs
    and `artisan` commands run with no request to read. Rather than an ambient
    value resolved at execution time — by which point the request that knew the
    answer is gone — the organization travels with the work:
    - Reactors read it from the event they are handling. Decision 10 already puts
      organization context on every new event, so the event is the carrier and no
      ambient state is needed.
    - Work that is not event-driven takes the organization as an explicit
      argument, captured at dispatch and serialized into the job payload.
    - Console commands take an explicit `--organization` option. A command given
      none operates on nothing rather than on everything. This is its own rule,
      not the header's: a request comes from a person whose memberships are
      known, so starting them in one of their own organizations is safe, while a
      command has no such person behind it and no organization to fall back to.
    - The lookup that *decides* the organization cannot itself be scoped by it.
      Resolving a provisioning token to its bank, binding a compartment from a
      URL, or finding the record an event refers to all happen before — or
      instead of — an organization being known. Scoped, they match nothing: a
      device is told its token is invalid, a locker its owner holds is "not
      found", a door change is never broadcast. Each of these took running the
      system to find, because the test suite resolves an organization before the
      request begins and so never reaches the ordering that fails.
20. **Groups are isolated data, not the isolation boundary.** Groups are
    organization-owned like everything else, but they must not be reused as the
    tenancy mechanism. A group manages who may reach which compartments inside
    one operator; an organization is the boundary between operators. The two look
    similar enough that "an organization is just a top-level group" will be
    proposed eventually, and it collapses the boundary into ordinary access data
    that any group-management bug can cross.
21. **The IoT contract does not change, and the simulator is the evidence.**
    Locker UUIDs and MQTT identities stay globally unique, topics are unchanged,
    and `locker-client` is untouched; organization ownership is derived through
    the locker bank. `locker-client` already ships a scenario-driven fleet
    simulator (`src/main-simulator.ts`) that boots several simulated banks in one
    process, each authenticating as its own globally unique locker-bank identity.
    Nothing on the wire carries an organization, so no simulator change is
    required — and a scenario holding banks from two different organizations
    gives an end-to-end isolation test on one broker for the cost of a YAML file.
22. **Adding a person whose account already exists joins that account.** An
    organization admin creates users by email. Because `users.email` is
    globally unique, an address that already has an account, in any
    organization, adds that account to the admin's organization instead of
    failing. There is no invitation to accept:
    - the email is matched ignoring case and surrounding spaces
    - the existing account is never changed: the name typed is ignored and the
      password stays, because another organization may depend on both
    - the person gets an email naming the organization and who added them,
      replying to that admin, instead of a password reset they did not ask for
    - every addition, of a new or an existing account, records a
      `UserJoinedOrganization` event in the organization's audit log
    - someone who is already a member is refused with a form error
    - terms still apply per organization (decision 18), so the person must
      accept the new organization's terms before its lockers work for them

    The admin learns only what they asked about: that the address now belongs
    to their organization. They cannot see or edit what the account holds
    elsewhere. The cost is consent: any organization admin can add any existing
    account and email it. That is acceptable while operators on one
    installation trust each other; an installation serving operators that do
    not should replace this with invitations the person accepts.

    Removing is the mirror image. When an organization admin deletes someone
    who also belongs to another organization, the person is removed from this
    organization only, and it looks exactly like a deletion, so the admin
    learns nothing about other organizations. Someone who belongs only to this
    organization is deleted for good.
    - removal takes everything the person held here with it: compartment
      access, group memberships and roles, each revoked as an event, so nothing
      reappears if they are added again and no door updates reach them
    - the person gets an email naming the organization and who removed them;
      a deleted account gets none, since nobody is left to write to
    - the last admin of an organization can be neither deleted nor removed
    - the last platform admin cannot be deleted; `platform-admin:grant` stays
      the way back in from the console
    - a platform admin's own delete removes the account itself

## Rationale

Shared schema is the only alternative that serves a managed service and the open
core with one codebase. Deployment isolation is genuinely cheaper below roughly
a dozen operators and gives isolation the database enforces by existing, but its
infrastructure cost grows linearly with customers and it cannot fund an
affordable managed service at scale. A database per organization keeps most of
that isolation but multiplies every migration and fragments the event store,
which event sourcing makes materially worse than it sounds. Maintaining tenancy
outside the core would split authorization into two implementations, and the one
that is off by default is the one that rots.

Keeping ownership always on, rather than switching it with the mode, follows the
same reasoning: a code path exercised only by managed-service installations is a
code path nobody tests.

Membership as a relation rather than a column on `users` is the one place this
ADR deliberately builds more than a single-organization install needs. `users.email`
is globally unique, so one organization per user would force a person working for
two operators into two accounts with two email addresses. Retrofitting a join
table later means migrating every scoped query and every role event. The table
costs almost nothing now and buys the option.

## Alternatives Considered

### Alternative A: Deployment isolation (one backend, database and broker per organization)

- Pros: strongest isolation, enforced by infrastructure rather than code; no
  application complexity; fits self-hosting perfectly; no migration of history.
- Cons: infrastructure cost and operational toil grow linearly with customers;
  no shared platform administration; cross-organization users impossible.
- Why not chosen: it cannot underpin an affordable managed service at scale, which
  is the point of #238. It remains the better answer below roughly a dozen
  operators, and is the honest fallback if the managed service stays small.

### Alternative B: Database or schema per organization

- Pros: strong isolation without separate deployments; a leaked scope cannot
  cross a connection boundary.
- Cons: every migration runs N times; connection switching on every request and
  every queued job; the event store fragments, so replay and projections become
  per-tenant operations.
- Why not chosen: the operational cost lands hardest exactly where this codebase
  is most complex — event sourcing, projectors and reactors.

### Alternative C: Tenancy maintained outside the open core

- Pros: self-hosters carry no tenancy complexity at all; the core stays simple.
- Cons: two authorization implementations; the open core's authorization would
  drift from the one the managed service actually runs.
- Why not chosen: it contradicts "one stable core, many applications", and the
  security-critical path is the worst possible place to maintain a fork.

### Alternative D: One organization per user (a nullable column on `users`)

- Pros: no join table; no organization selection anywhere; the mobile API keeps
  identical response shapes and needs no client regeneration.
- Cons: `users.email` is globally unique, so a person serving two operators needs
  two accounts and two email addresses; retrofitting membership later means
  migrating every scoped query and every role event.
- Why not chosen: it is a one-way door for a saving that the optional mode already
  delivers — a single membership is invisible in the UI anyway.

## Consequences

### Positive

- One authorization path, exercised by every installation on every request.
- Self-hosted installations keep a single-organization experience with no tenant
  selection and no organization-management workflows.
- Organization-awareness concentrates in two seams rather than scattering across
  42 capability checks.
- The IoT contract, MQTT topics and `locker-client` are untouched.
- Isolation is testable: cross-organization access should fail closed, and that is
  a test rather than a review convention.

### Negative

- Authorization stops being a property of a user and becomes a property of a
  (user, organization) pair. Every future capability check must know which
  organization it is asking about.
- Domain events gain organization context, so the event schema changes and the
  replay path grows a historical special case that never goes away.
- A join table exists that single-organization installations never use.
- Filament, broadcasts, notifications, queued side effects, CLI commands and audit
  queries all need an explicit organization context.

### Risks

- **A missed scope leaks one operator's data to another.** Mitigation: database
  constraints beneath the application scopes, fail-closed route model binding, and
  tests that assert cross-organization access is refused.
- **Broadcast authorization is the likeliest gap**, because it is the one path that
  does not go through the REST scoping. Mitigation: verify organization ownership
  in the channel authorization callbacks, and test it.
- **The database half of the isolation is not exercised by the test suite.** Dev
  and production run PostgreSQL, but the suite runs SQLite in memory, which
  cannot `ALTER TABLE ... ADD CONSTRAINT`. The composite foreign keys and the
  check constraint are therefore driver-guarded and absent under test, so
  everything CI proves is the application half. Mitigation today is verifying
  each constraint by hand against PostgreSQL; the real fix is running the suite
  on PostgreSQL, which is its own decision because it slows every test run.
- **The default-organization backfill is effectively irreversible** once a second
  organization exists. Mitigation: perform it as an explicit, reviewed migration
  with a verified backup, not as a side effect of deploying.
- **`platform_admin` concentrates power, and operators cannot see it used.**
  Mitigation: decision 13 makes entry into an organization an explicit,
  recorded act, reviewable by the other platform admins. An installation whose
  operators do not trust the host should show them these records instead.

## Rollout / Migration

1. Introduce `organizations` and `organization_user`; add `organization_id` to
   `user_roles` and to organization-owned tables.
2. Backfill: create the default organization, attach every existing user, assign
   every locker bank and role row to it. Existing admins become admins of the
   default organization, so nobody loses access on upgrade: everything they
   could do before happens inside that one organization.
3. Nobody becomes `platform_admin` automatically. An installation administrator
   is not necessarily the person who should administer every future operator,
   and promoting every admin at once would hand installation-wide access to
   people who never asked for it. The first platform admin is appointed with
   `platform-admin:grant`, console-only because reaching the server is the
   authorization; after that, platform admins can appoint others in the panel.
   Rolling the feature back revokes `platform_admin` from everyone.
4. Make the two authorization seams organization-aware; add the global scope and
   the database constraints together, never separately.
5. Add organization context to new domain events; map historical events to the
   default organization on replay.
6. Scope Filament resources, pickers, broadcasts, notifications and queued side
   effects. Keep the multi-organization UI disabled by default.
7. Fallback: until step 5 lands, the change is reversible by dropping the columns.
   Afterwards it is not, because events are immutable.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #238 (this decision), #249 (the terms gate the mobile question
  touches), #202 (audit-log classification, adjacent to scoping audit views)
- Related docs: `VISION.md`, ADR-0022 (manager/admin boundaries), ADR-0060 (open
  refusals decided inside the open path)
- Related PRs: none yet
