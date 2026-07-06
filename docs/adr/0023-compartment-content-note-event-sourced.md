# ADR-0023: Compartment content note (event-sourced) replaces the structured Item domain

## Status

Accepted

## Date

2026-06-15

## Context

For v1 we want users to record **what is inside a compartment** without building a
full inventory system. A single free-text note per compartment is enough: *"Winter
tires (set of 4), back-left corner."* The note must be **auditable** — we need to know
**who** changed it and **when** — and editable by **any user who has access** to that
compartment (issue #49).

The system previously modeled compartment contents as a structured **Item** entity
(`items` table, `Item` model, `ItemController`, `GET /api/items`, an `item` field on the
accessible-compartments response, a Filament `ItemResource`, an image-copying factory, and
a `total_items` admin statistic). In practice this was heavier than the need: users only
want to jot down what's inside, and a structured item sitting next to a free-text note gave
two parallel ways to describe the same thing, which was confusing in the end-user UI. The
`Item` model is **plain Eloquent — not part of the event-sourcing streams** — and no other
table has a foreign key into `items`, so it can be removed cleanly.

Relevant existing structure:

- Domain state is **event-sourced** (`spatie/laravel-event-sourcing`). All events land
  in the single `stored_events` table; each event belongs to an **aggregate** keyed by a
  uuid, which groups it into a per-thing stream.
- `Compartment` uses `HasUuids`, so its primary key **is** a uuid — usable directly as an
  aggregate identifier.
- `CompartmentProjector` already builds the `compartments` read model from compartment
  events (`Compartment::find($event->compartmentUuid)` → update columns).
- Access is resolved by `CompartmentAccessService::hasActiveAccess(User, Compartment)`,
  which already folds in **direct and group** access (ADR-0020).
- The mobile list is `GET /api/compartments/accessible` →
  `CompartmentController::accessible()` → `AccessibleCompartmentsResource`. The mobile app
  consumes a TypeScript RTK Query client generated from the live OpenAPI spec (ADR-0018),
  so any change to this response shape requires a client regeneration.

Two existing compartment aggregates are about **other** lifecycles —
`CompartmentOpenAggregate` (open requests/authorization) and `CompartmentAccessAggregate`
(who may enter). Neither represents "the compartment as a thing," so neither is a natural
home for note edits.

This touches the **API contract** (response shape, a new endpoint, and a removed
endpoint/field), the **DB schema**, the **admin panel**, and is **cross-component**
(backend + mobile), so an ADR is warranted per the repo rules. The genuine decisions to
record are: that the note **replaces** the Item domain, where the current note is stored,
where its history lives, which event stream owns it, and the validation envelope.

## Decision

Adopt a single, lightweight **content note** as the only per-compartment "contents"
descriptor, and **remove the structured Item domain** entirely.

### 1. Remove the Item domain

Delete the `Item` model, `ItemController`, the API `ItemResource`, the `compartments.item`
relation, the `item` field from `AccessibleCompartmentsResource` and `CompartmentResource`,
the `GET /api/items` route, the `total_items` admin statistic, the `ItemFactory` and its
seeder usage, and the Filament `ItemResource`. Add a migration that drops the `items` table.

### 2. Add the content note (event-sourced)

Record note edits as an **event-sourced** flow and project the current value onto the
existing `compartments` read model.

**Storage (read model) — denormalize onto `compartments`.** Add three nullable columns:

- `content_note` (`string`, length 80) — the current note; `null` means "no note".
- `content_note_updated_at` (`timestamp`) — when it last changed.
- `content_note_updated_by_user_id` (FK → `users`) — who last changed it.

The full history (every prior value, actor, timestamp) lives in `stored_events`; we do
**not** add a separate history/projection table.

**Event stream — its own per-compartment aggregate.** Add a small
`CompartmentContentNoteAggregate`, retrieved by the compartment's uuid, recording a single
event:

- `CompartmentContentNoteUpdated { compartmentUuid, actorUserId, note (nullable), }`
  (`created_at` on the stored event supplies the "when").

It is **not** folded into the open or access streams — those tell different stories, and
mixing "note changed" into "door opened" muddies both.

**Projection.** Extend the existing `CompartmentProjector` with an
`onCompartmentContentNoteUpdated` handler that writes the three columns onto the matching
`compartments` row (mirroring the existing `Compartment::find($event->compartmentUuid)`
handlers).

**Validation — plain text, trimmed, max 80 chars, empty clears.** Request rule:
`['present', 'nullable', 'string', 'max:80']`. The value is trimmed; an empty/blank value
is stored as `null` (clears the note). `max:80` counts **characters** (multibyte-safe), so
emoji/accents count as one each. The note is treated as plain text everywhere (React Native
renders text as text; Filament auto-escapes), so no extra sanitization is needed.

**Authorization.** A user may update the note when
`CompartmentAccessService::hasActiveAccess(user, compartment)` is true (direct **or**
group) **or** the user is an admin. Enforced via a Policy/Form Request, never inline in the
controller.

**API.**

- `GET /api/compartments/accessible` includes `content_note` (and the two audit fields) in
  `AccessibleCompartmentsResource`, and **no longer includes `item`**. **This changes the
  response shape** → regenerate the mobile client (`pnpm generate:api` with the backend
  running).
- New `PUT /api/compartments/{compartment}/content-note` with body `{ "note": <string|null> }`,
  routed through the controller → service → `CompartmentContentNoteAggregate`. Mirror the
  `compartments.open` route's `verified.api` middleware for write-action parity.

**Admin (Filament) — surface the note and its audit trail.** Because the captured history
is only useful if a human can review it, the admin panel exposes both:

- A read-only **`content_note` column** (label "Note") on the **Locker Banks → Compartments**
  relation manager (compartments have no standalone resource; they live under their locker
  bank). It shows the current note, with a "Updated <relative time>" description.
- Clicking the note opens a **read-only history modal** listing every
  `CompartmentContentNoteUpdated` event for that compartment — value (or "Note cleared"),
  actor name, and timestamp — read straight from the event store
  (`EloquentStoredEvent` filtered by `aggregate_uuid` = compartment uuid). No projection
  table is added; the modal is purely a viewer.

The note is **not** editable from Filament in v1 (any future edit must route through
`CompartmentContentNoteAggregate`, never a direct column write, to keep the audit trail
intact).

## Rationale

One concept beats two: the note already covers the user-facing need, so keeping a parallel
structured item duplicated meaning and confused the UI — removing it leaves a single,
clear descriptor and a leaner contract/admin/DB. The note is one small piece of text we
**always** want alongside the compartment when we show it, so denormalizing onto
`compartments` keeps the hot `accessible` read a single lookup with no join — consistent
with the read-model approach in ADR-0020. Event sourcing already gives us a permanent,
ordered audit trail for free, so a dedicated history table would be redundant for v1. A
dedicated per-compartment aggregate keeps the new event stream semantically clean and
matches the existing aggregate/projector idiom. Because storage sits behind a JSON
Resource, the storage choice is invisible to the mobile app and reversible (see below).
Removing Item is low-risk: it is not event-sourced and nothing has a foreign key into it.

## Alternatives Considered

### Alternative A: Keep the Item domain alongside the note

- Pros: no removal work; admins keep structured item CRUD and images.
- Cons: two parallel ways to describe contents; an unused, misleading API field/relation;
  ongoing eager-load cost; more code, tests, and admin surface to maintain.
- Why not chosen: the explicit goal is a single lightweight descriptor; leaving Item in
  place keeps the confusion server-side.

### Alternative B: Separate projection table for notes

- Pros: keeps `compartments` lean; room to grow into richer notes (multiple notes,
  attachments) later.
- Cons: adds a join to every `accessible` list render for one text field; more code for no
  v1 benefit.
- Why not chosen: the ticket explicitly says keep it lightweight (no inventory system).
  Switching to this later is cheap **because** the read model is rebuildable: add the table,
  write a projector, `php artisan event-sourcing:replay`, repoint the Resource — the events
  (the source of truth) never change, and the API shape stays identical.

### Alternative C: Ride on an existing aggregate (open or access stream)

- Pros: no new aggregate class.
- Cons: pollutes a stream that means something else; harder to reason about and to read back
  a compartment's note history in isolation.
- Why not chosen: the note is a distinct concern with its own lifecycle; a tiny dedicated
  aggregate is clearer and cheap.

### Alternative D: Plain column updated directly (not event-sourced)

- Pros: simplest possible — one `UPDATE`.
- Cons: no audit trail; we'd have to bolt on separate history logging.
- Why not chosen: auditability (who/when) is a hard requirement, and event sourcing is the
  established pattern here.

## Consequences

### Positive

- Single, clear contents model (the note); leaner API, admin, and DB; less code and fewer
  tests to maintain.
- Note shown next to the compartment with no extra join; mobile list stays one read.
- Full who/what/when history for free in `stored_events`; rebuildable by replay.
- The audit trail is **reviewable by admins** in Filament (note column + history modal),
  not just queryable in the database — so "auditable" is met at both the capture **and**
  the review layer.
- Small, idiomatic surface: one event, one tiny aggregate, one projector handler, one
  endpoint, one Resource field.

### Negative

- Structured item data (name/description/image) is gone; there is no longer any
  per-compartment image or structured attributes.
- The `accessible` response shape changes (adds note fields, drops `item`) → a coordinated
  mobile client regeneration is required, or types drift.
- Three audit columns added to `compartments`.

### Risks

- Any client relying on `item` (from `GET /api/compartments`), `GET /api/items`, or
  `total_items` breaks. Mitigation: the only consumer is the mobile app, regenerated against
  the new spec in the same change; the grid card now shows the content note instead of the
  item.
- The drop-items migration's `down()` is best-effort (recreates an empty table); item data
  is not recoverable after rollout.
- Edit spam writes many events (minor `stored_events` growth). Acceptable for v1; rate
  limiting can be added later if needed.
- Authorization must distinguish **read** (anyone who can see the compartment also sees its
  note) from **write** (only active-access or admin). Mitigation: enforce write via Policy +
  feature tests covering access/no-access/expired/admin.

## Rollout / Migration

1. Backend domain core — migration (3 columns) + `CompartmentContentNoteUpdated` event +
   `CompartmentContentNoteAggregate` + `CompartmentProjector` handler + service method +
   Form Request/Policy.
2. Remove the Item domain — delete the model, controller, API + Filament resources,
   relation, route, factory, seeder usage, and `total_items` stat; add the
   `drop_items_table` migration and run `php artisan migrate`.
3. API surface — `PUT .../content-note` route + `content_note` in
   `AccessibleCompartmentsResource` (and `item` removed); document for Scramble;
   feature-test the authz matrix and validation (max 80, trim, empty-clears).
4. Mobile — `pnpm generate:api` against the running backend; display the note in the
   compartment UI (grid card + sheet) and add an edit flow for users with access.
5. Admin — add the read-only `content_note` column + history modal to the
   `CompartmentsRelationManager` (history read from `EloquentStoredEvent`).

No data migration is needed for the note; existing compartments start with
`content_note = null`. Dropping `items` discards any existing item rows.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #49
- Related ADRs: ADR-0020 (group-based access, `hasActiveAccess`), ADR-0018 (codegen from live
  OpenAPI URL)
- Related code: `app/Models/Compartment.php`, `app/Projectors/CompartmentProjector.php`,
  `app/Services/CompartmentAccessService.php`, `app/Http/Controllers/CompartmentController.php`,
  `app/Http/Resources/AccessibleCompartmentsResource.php`, `routes/api.php`
