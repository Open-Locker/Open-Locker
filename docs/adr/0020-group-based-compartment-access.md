# ADR-0020: Group-based compartment access (event-sourced) with a projected effective-access read model

## Status

Accepted

## Date

2026-06-04

## Context

Compartment access today is **per-user only**. `CompartmentAccessAggregate` records
`CompartmentAccessGranted` / `CompartmentAccessRevoked` events; `CompartmentAccessProjector`
builds rows in `compartment_accesses`; and both hot paths read only that table:

- `CompartmentAccessService::hasActiveAccess()` — drives open authorization (`requestOpen()`).
- `CompartmentController::accessible()` — the `GET /api/compartments/accessible` list.

Operationally, access is usually shared by teams/initiatives, so granting each compartment to each
user individually is high-overhead. We want **groups**: admins put users in groups, grant groups
access to compartments, and a user's **effective access = direct ∪ group-derived**. The mobile
API must expose this transparently (no client change).

Constraints:
- Domain state must stay **event-sourced** (events + projectors), with **actor/audit** parity to
  the direct-access path.
- The hot read paths must avoid expensive runtime joins.
- Auditability (who granted/revoked membership and access, and when) must be preserved.

## Decision

Add groups as a **parallel event-sourced model** (not folded into `users` or `compartment_accesses`)
and resolve effective access through a **projected read model**.

**Data model (read models, all built by a projector):**
- `groups` — the group.
- `group_user` — membership (user ∈ group), with actor + optional `expires_at`.
- `group_compartment_accesses` — group → compartment grant (parallel to `compartment_accesses`;
  `compartment_id` is a `foreignUuid`).
- `user_group_compartment_accesses` — **projected effective table**: the flattened (user ↔ compartment)
  access derived from `membership × group grants`. Direct access stays in `compartment_accesses`;
  **effective = the union of the two**, evaluated as one extra indexed lookup (no multi-join).
  Unique on `(user_id, compartment_id)`; index pattern mirrors `compartment_accesses`. Optional
  `group_id` column for audit (which group contributed the derived row). Projected `expires_at` =
  earliest non-null of membership `expires_at` and group grant `expires_at`; enforce expiry at read
  time via the existing `active()` scope.

**Event sourcing:** a single `GroupAggregate` per group (the consistency boundary) records
`GroupCreated`, `UserAddedToGroup`, `UserRemovedFromGroup`, `GroupCompartmentAccessGranted`,
`GroupCompartmentAccessRevoked`. A `GroupProjector` maintains all four tables and **recomputes**
`user_group_compartment_accesses` whenever a membership or group-grant event fires — scoped to the
affected group/members only (incremental recompute, not a full-table rebuild). Register
`GroupProjector` in `config/event-sourcing.php` (Slice 1).

**v1 lifecycle:** groups **cannot be deleted** — no Filament delete action, no `GroupDeleted` event.
Archiving (event-sourced `GroupArchived`, read-model update, ending effective access) is tracked in
#106.

**Semantics — additive / union:** a user has access if **any** active source grants it. Revoking one
source (a direct grant, a group grant, or a membership) removes only that source; other active
sources still apply. Each source respects its own `revoked_at` / `expires_at` via the existing
`active()` scope.

**Integration (hot paths):**
- `hasActiveAccess()` returns true if an active row exists in `compartment_accesses` **or**
  `user_group_compartment_accesses`.
- `requestOpen()` sets `authorizationType` by checking direct access first (`'granted_access'`), then
  group-derived access (`'group_access'`) for audit clarity.
- `CompartmentController::accessible()` includes compartments reachable directly **or** via a group;
  the `AccessibleCompartmentsResource` shape is unchanged (mobile transparent).
- `CompartmentStatusBroadcastService::recipientUserIdsForCompartment()` unions direct and
  group-derived access (Slice 2).

**Authorization:** management (create group, membership, group grants) stays **admin-only** for now,
reusing `CompartmentAccessService::ensureCanManageAccess()`. Manager-role support (#95) is out of scope.

## Rationale

A projected effective table keeps the mobile `accessible` call and open-authorization to a single
indexed lookup instead of a `user → group_user → group_compartment_accesses` join on every request.
Additive/union is the least-surprising sharing model and the simplest to reason about and test.
Mirroring the existing aggregate/projector/`active()`-scope pattern keeps the codebase consistent
and the new access just as auditable as direct access.

## Alternatives Considered

### Alternative A: Resolve effective access with query-time joins (no projected table)

- Pros: less code; no recompute logic; no derived table to keep consistent.
- Cons: multi-join on every hot read (`accessible`, open-authorization); cost grows with
  members/groups.
- Why not chosen: the read paths are hot and latency-sensitive; the issue explicitly calls out
  avoiding expensive runtime joins.

### Alternative B: Direct-revoke-overrides (deny-override) semantics

- Pros: supports an explicit "ban this user from compartment X" even if a group would grant it.
- Cons: more complex precedence rules and edge cases; surprising ("I revoked them but they still
  have access via a group" vs "their group grant silently does nothing").
- Why not chosen: no current requirement for deny-override; additive/union is simpler and matches
  how shared access is expected to work. Can be revisited in a future ADR if banning is needed.

## Consequences

### Positive

- Effective-access reads stay a single indexed lookup; mobile API unchanged.
- Group membership and group access are fully event-sourced and auditable, like direct access.
- Clear, predictable union semantics; straightforward test matrix.

### Negative

- The projector must **recompute** `user_group_compartment_accesses` on membership and group-grant
  changes — an O(members × granted-compartments) write-time cost per change (scoped per affected
  group/members, not a full-table rebuild).
- A second access source means more authorization branches and a larger test surface.

### Risks

- Projection drift if recompute logic is incomplete (e.g. a removed member keeps derived rows).
  Mitigation: cover the full add/remove × grant/revoke × expiry matrix with feature tests; the table
  is rebuildable by replaying events.
- Time-based expiry is enforced at read time via `active()` (not by an event), consistent with the
  existing direct-access behavior.

## Rollout / Migration

Deliver in reviewable slices after this ADR is accepted:
1. Domain core — migrations + models + `GroupAggregate` + events + `GroupProjector` (registered in
   `config/event-sourcing.php`) + `GroupAccessService`.
2. Effective-access wiring — `hasActiveAccess()` + `accessible()` +
   `CompartmentStatusBroadcastService::recipientUserIdsForCompartment()`; feature-test the
   union/expiry/revoke matrix.
3. Filament UI — `GroupResource` + members and compartment-access relation managers (routed through
   `GroupAccessService`, never direct model writes); no delete action in the admin UI (v1).

No data migration of existing direct access is required; direct access keeps working unchanged.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #46 (builds on the direct-access model; complements #48 navigation, relates to #55),
  #106 (group archiving follow-up)
- Related code: `app/Aggregates/CompartmentAccessAggregate.php`, `app/Projectors/CompartmentAccessProjector.php`,
  `app/Services/CompartmentAccessService.php`, `app/Models/CompartmentAccess.php`,
  `app/Http/Controllers/CompartmentController.php`
