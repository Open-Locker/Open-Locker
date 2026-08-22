  # ADR-0044: Bootstrap the first admin from configuration, not from registration order

> **Renumbered from ADR-0034** — ADR numbers were deduplicated and put in date order from 0018 up; see #214.

## Status

Proposed

## Date

2026-07-31

## Context

A fresh deployment has an empty `users` table and therefore no way to reach the admin
panel. The current answer to that was built early, when the alternative was running
`php artisan tinker` on the server, and consists of two independent mechanisms:

1. **`AdminPanelProvider.php:38`** — the Filament registration page is mounted only while
   `User::count() === 0`. Once anybody registers, the route disappears.
2. **`User::booted()`** — a `created` model hook grants admin to the new user whenever
   `User::count() === 1`.

Together they mean: the first person to find a freshly deployed instance registers and
becomes an administrator.

Two separate problems follow, and they are not equally important.

**The registration window is a narrow risk.** It is open only between deployment and the
first signup, and it closes permanently on its own. It requires an attacker to reach the
instance in that window. Real, but bounded.

**The model hook is the more serious defect.** It grants administrator rights as an
invisible side effect of `User::create()`, on *any* path — a seeder, a factory, an
imported dataset, a future public signup endpoint. Nothing at the call site suggests that
creating a user might make them an admin, and the condition it depends on
(`User::count() === 1`) is a property of the whole table rather than of the user being
created. The database seeder already works around this by calling `makeAdmin()`
explicitly, which is what the hook was supposed to make unnecessary.

One detail in issue #141 is now out of date: it asks whether the new roles are actually
granted or only a legacy `is_admin` flag. They are granted properly — `makeAdmin()`
records through `UserRoleAggregate` and the event-sourced role flow.

Constraint worth stating plainly, because it is what produced the current design: the
team wants first-admin setup to be possible **without shell access to the server**.

## Decision

**1. The first admin is declared in configuration, not discovered from registration
order.**

An `ADMIN_EMAIL` environment variable names the intended administrator. On deployment, if
no user with that address exists, one is created and granted the admin role through the
existing event-sourced flow. The account is created without a usable password; the
administrator obtains access through the normal password-reset flow.

Running it again is a no-op, so it is safe on every deploy rather than only the first.

Unlike the command in decision 3, this path is not blocked once an admin exists: pointing
`ADMIN_EMAIL` at a new address on a live instance will create that administrator. That is
intentional. Deployment configuration is already a trusted channel — whoever can set it
can also reach the database — so the restriction would add friction without adding a
guarantee.

**2. Both existing mechanisms are removed.**

- The conditional registration page in `AdminPanelProvider` goes away. The admin panel
  never exposes a registration route.
- The `created` hook in `User::booted()` that grants admin to user number one goes away.
  Admin rights are only ever granted by an explicit call.

**3. An artisan command is provided as an escape hatch.**

`php artisan first-admin:create {email}` performs the same operation on demand. It
exists for the case where `ADMIN_EMAIL` was not set before the first deploy, or where
mail is not yet working — see the risk below. It is not the primary path.

The command **does nothing once any admin exists**, and says to use the admin panel
instead. It is a bootstrap tool, not a general way to create administrators: promoting a
user is a panel operation that goes through the event-sourced role flow and leaves an
audit trail, and a shell command that quietly grants admin at any time would be a way
around that. The name says the same thing, so the behaviour and the name agree.

It exits successfully in that case, and also when no address is configured, because it
runs unattended on every deploy — failing over an already-satisfied condition would take
the container down. A malformed address is the one genuine failure.

## Rationale

Configuration is the right place for this because the identity of the first
administrator is a deployment fact, known by whoever provisions the instance, and not
something the application can infer. Registration order is a proxy for that fact which
happens to be correct only if nobody else arrives first.

Removing the `booted()` hook matters independently of how bootstrapping works. Privilege
escalation should be an explicit, greppable call, not an emergent property of table
size. It also removes a trap for anyone later adding a public signup endpoint, who would
have no reason to suspect that the first row created grants administrator rights.

Keeping an artisan command alongside the environment variable costs almost nothing and
covers the failure mode that would otherwise be unrecoverable without database access.

## Alternatives Considered

### Alternative A: Keep the current registration window

- Pros: no work; already understood by the team; needs neither shell access nor working
  mail.
- Cons: the first arrival becomes an administrator; the model hook keeps granting admin
  from any creation path.
- Why not chosen: the hook's blast radius is the problem, and it cannot be fixed while
  the bootstrap depends on it.

### Alternative B: Artisan command only, no environment variable

- Pros: simplest possible implementation; nothing runs automatically; no deployment
  prerequisite.
- Cons: requires shell access on the server, which is exactly the constraint that
  produced the current design.
- Why not chosen: it reintroduces the problem the existing hack was invented to solve.
  Retained as a secondary path rather than the only one.

### Alternative C: Seed a fixed default admin with a known password

- Pros: trivially reliable; no mail dependency; no configuration.
- Cons: a well-known credential exists on every deployment until someone changes it, and
  in practice someone will not.
- Why not chosen: it trades a narrow timing window for a permanent one.

### Alternative D: Registration open, but restricted to an allowlisted email domain

- Pros: keeps the no-shell, no-mail property; narrows who can claim the instance.
- Cons: still first-come-first-served within the domain; adds configuration without
  removing the underlying mechanism.
- Why not chosen: more moving parts than naming the administrator outright, for a weaker
  guarantee.

## Consequences

### Positive

- No path exists by which registering makes someone an administrator.
- Granting admin becomes explicit and greppable; adding a public signup endpoint later
  carries no hidden privilege escalation.
- Bootstrapping is idempotent and reproducible, so rebuilding an environment produces
  the same administrator.
- Still no shell access required on the normal path.

### Negative

- A new deployment prerequisite: `ADMIN_EMAIL` must be set, and its absence is only
  noticed when nobody can log in.
- Two ways to create the first admin instead of one, which must both be documented.

### Risks

- **Lockout when mail is not configured.** The environment-variable path creates an
  account with no usable password and relies on password reset, so an instance deployed
  before mail works has an administrator nobody can log in as. This is the main risk of
  the decision. Mitigations: the artisan command from decision 3, and documenting
  working mail as a prerequisite for first deployment.
- **Tests may depend on the removed hook.** Anything that creates a user and assumes
  administrator rights will start failing. This is desirable — those tests were relying
  on the behaviour this ADR removes — but it makes the change larger than it looks.
- **`ADMIN_EMAIL` changed later** does not demote the previous administrator; it only
  ensures the named account exists. Role changes remain a panel operation.

## Rollout / Migration

Existing deployments already have an administrator, so this is not a migration for them —
the bootstrap simply stops being reachable.

1. Add the `ADMIN_EMAIL` configuration entry (`config/admin.php`).
2. Add `php artisan first-admin:create {email?}`, a no-op when an admin already exists.
3. Run it on deploy from an `entrypoint.d` script in the image, gated on
   `AUTORUN_ENABLED`. Every container in the stack runs the entrypoint, but only the app
   container has that flag set — the same flag that decides which container migrates —
   so the gate is what stops six containers racing to create the same account.
4. Remove the registration toggle from `AdminPanelProvider`, and the now-orphaned
   `Filament\Pages\Auth\Register` page with it.
5. Remove the `created` hook from `User::booted()`, and fix the tests that depended on
   it by granting admin explicitly, as the database seeder already does.
6. Document `ADMIN_EMAIL` and the mail prerequisite in `.env.example`.

## Supersedes / Superseded By

- Supersedes: none. The behaviour being replaced predates the ADR process.
- Related: ADR-0022 (role-based access control) defines the role this grants.

## References

- Related issues: #141
- Related code: `app/Providers/Filament/AdminPanelProvider.php`, `app/Models/User.php`
  (`booted()`, `makeAdmin()`), `database/seeders/`
