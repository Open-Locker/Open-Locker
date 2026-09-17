# ADR-0060: Compartment-open refusals are decided inside the open path, not by middleware

## Status

Accepted

## Date

2026-09-17

## Context

`POST /api/compartments/{compartment}/open` sat behind two middleware that
answered before the controller ran:

- `terms.accepted` (`RequireAcceptedTerms`), applied to the whole authenticated
  route group, and
- `verified.api` (`EnsureVerifiedEmailApi`), applied per route.

Both short-circuit with a 403. That meant `CompartmentAccessService::requestOpen`
never ran for those attempts, so no `CompartmentOpenRequested` /
`CompartmentOpenDenied` event was recorded. The consequences were visible to
operators: a denied open attempt appeared nowhere in the admin audit log (which
reads `stored_events`) and nowhere in the "Last open status" column on the user
edit screen (which reads the `compartment_open_requests` read model). An admin
looking at a support request — "I pressed open and nothing happened" — had no
record that the user had tried at all.

The service already contained an `unverified_email` denial branch, which was
dead code for exactly this reason: the middleware always fired first.

The refusal bodies are a contract. The mobile app keys its terms re-acceptance
flow off `code === 'terms_not_accepted'` in the terms body, so that body cannot
change shape.

## Decision

Terms acceptance and email verification for the compartment-open route are
decided inside `CompartmentAccessService::requestOpen`, in that order, after the
`CompartmentOpenRequested` event has been recorded. Each failure records a
`CompartmentOpenDenied` event carrying the reason (`terms_not_accepted` or
`unverified_email`) and returns that reason to the caller.

The terms decision is opt-in per caller, through a required
`$requireAcceptedTerms` argument that every call site passes by name. Only
`CompartmentController::open` passes true.
The admin panel's Open action (`OpenCompartmentAction`) calls the same method
without it and is therefore unaffected, which is exactly how it behaves today:
the Filament panel has never sat behind the terms middleware. Email verification
stays unconditional for every caller, because it already was.

That asymmetry is deliberate rather than an oversight. Terms are accepted only
through `POST /api/terms/accept`, i.e. from the mobile app; the panel has no
acceptance UI, and activating a version is itself a panel action. Gating the
panel would mean an admin who publishes a new version loses the Open button with
no remedy anywhere in the panel. Email verification carries no such trap, since
admins are verified as a matter of course.

The open route alone opts out of the middleware: it drops `verified.api` and
carries `->withoutMiddleware('terms.accepted')`. Every other route in the group,
including `PUT /api/compartments/{compartment}/content-note`, keeps both gates
unchanged.

`CompartmentController::open` maps the returned reason back to a response body.
Those bodies are produced by static factories on the two middleware classes
(`RequireAcceptedTerms::notAcceptedResponse()`,
`EnsureVerifiedEmailApi::unverifiedResponse()`), which the middleware themselves
also use, so the two paths cannot drift apart.

The predicate is single-sourced the same way. `TermsService::hasAcceptedActiveVersion()`
matches an acceptance against the active version's **id**, and both the
middleware and the open route call it. Comparing version *numbers* instead would
diverge after a rollback: a user who accepted v2 has not accepted a re-activated
v1, though v1 is the lower number and their acceptance is the more recent one.

## Rationale

An open attempt is a domain fact whether or not it succeeds, and the audit trail
is the reason the open path is event sourced at all. A gate that refuses before
the domain is reached cannot record anything, so the only place the decision can
live is inside the path that records it.

Keeping the refusal bodies in the middleware classes, rather than copying the
literals into the controller, means the byte-identical response the mobile app
depends on is enforced by construction instead of by a comment.

## Alternatives Considered

### Alternative A: Have the middleware record the event before returning 403

- Pros: no route restructuring; the gate stays in one place.
- Cons: puts aggregate/event-sourcing writes into the HTTP middleware layer,
  which every other route in the group would also pay for; the middleware has no
  compartment in hand, so it would have to resolve the route binding itself.
- Why not chosen: it spreads domain writes into a layer that exists to be
  domain-agnostic, and the same middleware guards routes that have nothing to do
  with compartments.

### Alternative B: Restructure the route group so the open route sits outside the terms gate

- Pros: no `withoutMiddleware` call, which some read as a smell.
- Cons: splitting the group risks silently un-gating a neighbouring route now or
  when routes are added later; the grouping no longer reads as "everything here
  is gated".
- Why not chosen: one explicit per-route exclusion is easier to audit than a
  second group whose membership must be kept correct by hand.

### Alternative C: Add a new storable event for refused attempts

- Pros: separates "refused before authorization was considered" from "refused on
  access".
- Cons: a new event class, a new projector branch and a new audit whitelist
  entry, for a distinction the existing `reason` field already carries.
- Why not chosen: `CompartmentOpenDenied` with a reason already models this, and
  the audit presenter already renders the reason.

### Alternative D: Apply the terms gate to the admin panel as well

- Pros: one rule everywhere; no per-caller argument.
- Cons: the panel cannot accept terms, so it would need an acceptance UI and a
  `terms_not_accepted` string in the realtime toast, neither of which this issue
  asks for; until then an admin who activates a version is locked out of the
  Open button and `compartment_open_requests` fills with denials against them.
- Why not chosen: it introduces a new failure mode on a path that never had the
  gate, in the name of an audit-visibility fix.

## Consequences

### Positive

- Denied open attempts appear in the admin audit log and in the "Last open
  status" column, which is what issue #249 reported as missing.
- The previously unreachable `unverified_email` denial branch is now live over
  HTTP, and its behaviour is covered by tests.
- Refusal bodies are single-sourced, so the `terms_not_accepted` contract the
  mobile app depends on cannot drift.

### Negative

- The open route's gating is no longer readable from the route file alone; the
  route file carries a comment pointing at the service.
- One extra acceptance lookup on a refused open, to build the response body.
  The document itself is held for the life of the `TermsService` instance, so
  moving the predicate into the service did not add a per-request query to the
  gated writes that keep the middleware: those cost the same document lookup and
  acceptance check they did before.

### Risks

- A future route added to the group inherits the gate correctly, but a future
  change to `requestOpen` could reorder the checks and change which refusal a
  user who trips both sees. A test pins the terms-first order.
- `$requireAcceptedTerms` is required, with no default, so a new caller has to
  state which rule it wants and an omission is a static error rather than a
  silently skipped gate. Every call site passes it by name, so a bare positional
  boolean never appears. A test pins that the API route refuses and that the
  panel does not.
- The terms rule is now asymmetric between the API and the admin panel. That
  asymmetry is pre-existing — it is what the middleware already produced — but
  it is now written in the service rather than implied by where the route sits,
  so it needs to be revisited if the panel ever grows an acceptance UI.
- `User::hasAcceptedCurrentTerms()` still compares version numbers and is left
  alone, but calling its callers display-only would be wrong. One is
  (`app/Filament/Resources/UserResource.php`); the other populates the
  `terms_current_accepted` API field (`app/Http/Resources/UserResource.php`),
  which the mobile app navigates on — `app/(tabs)/_layout.tsx` and
  `app/sign-in.tsx` route the user to the terms screen from it. After a rollback
  the two predicates disagree in the awkward direction: the gates would let the
  user open a compartment while the number comparison still reports the newer
  acceptance as current, so the app can strand them on the terms screen. It
  fails safe in the sense that nothing is opened that should not be, but it is a
  user-visible dead end, not a cosmetic mismatch. Unifying it means touching a
  field the mobile app consumes, so it belongs in its own change rather than
  here.
- The refusal bodies are described in the controller's `@response` docblocks,
  but Scramble 0.13 does not read that tag, so they are not in the generated
  spec — as they were not on main, where middleware produced them. Documenting
  them for real needs `#[Response]` attributes, and OpenAPI allows one response
  per status code, so all three 403 shapes have to become a single `oneOf`.
  That changes the spec the mobile client is generated from, so it is its own
  change.
- The admin panel's realtime toast
  (`resources/views/filament/realtime-compartment-open-notifications.blade.php`)
  maps only `unverified_email` and `missing_active_access`, so any other reason
  renders as the generic "not authorized" line. No panel path can now produce
  `terms_not_accepted`, so nothing is mislabelled today; a new denial reason
  reachable from the panel would need a string added there.

## Rollout / Migration

No migration. No schema, event or response-shape change; the read model and the
audit log simply start receiving rows they were never reaching before. Rolling
back is reverting the route exclusion and the service checks together.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs:
- Related issues: #249, #250
- Related docs: `locker-backend/app/Services/CompartmentAccessService.php`,
  `locker-backend/app/Services/TermsService.php`,
  `locker-backend/app/Support/Audit/AuditEventPresenter.php`
