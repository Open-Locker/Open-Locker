# ADR-0019: Split user `name` into `first_name` and `last_name`

## Status

Accepted

## Date

2026-06-02

## Context

User accounts previously stored a single `name` column. In practice the mobile
app and admin workflows treated that field as a **given name** only; a separate
family name was not captured.

For **legal and operational traceability**, we need to identify natural persons
reliably when they:

- accept terms (AGB / privacy policy), recorded per `user_id` with version and
  timestamp (see [ADR-0001](0001-terms-versioning-and-acceptance-gate.md))
- open or empty compartments (event-sourced `actorUserId` on compartment access
  and open-request events)
- appear in Filament audit views (who granted access, who published terms, who
  acted on an open request)

Audit rows reference `users.id`; administrators and exports must be able to
resolve that id to a **clear, human-readable identity** (given name + family
name), not an ambiguous single string.

Keeping one unstructured `name` field does not enforce collection of both parts
and makes exports and support workflows harder to standardize.

## Decision

Replace `users.name` with:

- `first_name` — required string (max 255) at API and application level
- `last_name` — required string (max 255) on **register** and **profile update**
- `last_name` column remains **nullable in the database** only so migrated legacy
  rows are not blocked at the schema layer; API validation enforces both names
  for all new writes and profile edits

Expose both fields on the REST API (`User` resource, `TokenResponse`, register
and profile update payloads). Responses may still return `last_name: null` for
legacy accounts until they complete their profile. Provide `User::fullName()`
(trimmed `first_name` + `last_name`) for display in Filament and other read-only
contexts.

**Data migration:** copy existing `name` into `first_name`; set `last_name` to
`NULL`. No automatic splitting of combined strings (e.g. `"Max Mustermann"` stays
in `first_name` until the user updates their profile and supplies a family name).

**Mobile:** collect and edit first and last name separately; require both before
saving the profile; use `formatUserName()` for display strings (avatar initial,
stored greeting name).

## Rationale

- Structured fields support consistent reporting and admin search by given or
  family name.
- `fullName()` gives one canonical display string without duplicating logic in
  Filament columns.
- API-level requirement on register/profile ensures legally relevant actions are
  tied to identifiable persons going forward, without a `NOT NULL` constraint that
  would complicate one-shot migration of existing rows.
- Event-sourced audit continues to use `user_id`; name fields live on the
  current `users` row and reflect the identity at the time of later lookups
  (profile updates change the row, not historical event payloads — same as
  before for email).

## Alternatives Considered

### Alternative A: Keep single `name` and validate “full name” in the app

- Pros: No API or schema break.
- Cons: Weak structure; hard to search/sort; no standard for legal exports.
- Why not chosen: Does not meet the goal of reliable given + family name capture.

### Alternative B: Store `legal_name` as one required field plus optional display name

- Pros: One column for legal identity.
- Cons: Still unstructured; duplicates the old problem.
- Why not chosen: Split fields are clearer for forms and i18n (Vorname/Nachname).

### Alternative C: `NOT NULL` on `last_name` in the database

- Pros: Strongest schema enforcement.
- Cons: Migration must backfill or fail for every legacy row with missing family
  name; blocks deploy until data is cleaned.
- Why not chosen: Nullable column + required API validation gives the same
  behavior for new data without blocking migration.

## Consequences

### Positive

- Register and profile flows always capture given and family name.
- Filament user forms require both fields for admin-created users.
- Legacy users can still log in and must set `last_name` on next profile save.

### Negative

- **Breaking API change:** clients must send/read `first_name` and `last_name`
  instead of `name` (coordinate backend + mobile deploy).
- Migrated combined names remain entirely in `first_name` until corrected manually.
- `GET /user` may return `last_name: null` until legacy users update their profile.

### Risks

- Legacy accounts with `last_name = null` cannot pass profile validation until
  they provide a family name — acceptable gate for legal completeness.
- Historical events do not snapshot name text — rely on `user_id` + current user
  row (documented limitation, unchanged from prior model).

## Rollout / Migration

1. Run migration `2026_05_23_000001_split_user_name_fields`.
2. Deploy backend (API + Filament).
3. Deploy mobile app build that requires both name fields on profile save.
4. Regenerate mobile client: `pnpm generate:api` against live `/docs/api.json`
   (see [ADR-0018](0018-codegen-from-live-openapi-url.md)).
5. Communicate to operators: migrated accounts must add family name on next login
   / profile edit; verify admin-created users include both names.

## Supersedes / Superseded By

- Supersedes: none (first decision on user name shape).
- Superseded by: none.

## References

- PR: https://github.com/Open-Locker/Open-Locker/pull/91
- Related: [ADR-0001](0001-terms-versioning-and-acceptance-gate.md) (terms acceptance audit)
- Related: [ADR-0018](0018-codegen-from-live-openapi-url.md) (OpenAPI codegen after API change)
