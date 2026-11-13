# ADR-0082: Provisioning tokens expire after 24 hours

## Status

Implemented (2026-11-13, #331).

## Date

2026-10-19

## Context

The backend's provisioning token never expires. A token printed or shown in the
admin panel stays valid until it is used or the bank is reset. Commissioning
over a radio ([ADR-0074](0074-esp32-softap-provisioning.md)) is a longer step
with more exposure, which makes an unlimited token harder to defend. The rule
applies to every client, the Pi included.

## Decision

A provisioning token is valid for **24 hours** from issue.

- The projector sets `provisioning_token_issued_at` from the event's
  `issuedAtIso8601` and clears it when the token is used or the bank is reset.
- `acceptRegistration` refuses a token past 24 hours (expired at exactly 24 h).
  A row with a token hash and no issue time counts as expired.
- The device-facing reply stays success or failure. The backend logs a refused
  expired token with the reason "expired".
- The admin token dialog says the token is valid for 24 hours.

## Rationale

A time limit bounds what a leaked, unused token is worth, and a lost one is
replaced from the admin panel. A distinct "expired" reply would change the
AsyncAPI contract for no device-side benefit.

## Alternatives Considered

### Alternative A: An "expired" error code to the device

- Pros: the installer's tool could say why.
- Cons: a contract change, and the portal is already closed by then
  ([ADR-0074](0074-esp32-softap-provisioning.md)).
- Why not chosen: no device-side benefit.

### Alternative B: Record a refused token as an audit event

- Pros: visible in the audit log.
- Cons: needs a new stored event, a presenter entry and translations.
- Why not chosen: the log line is enough for now.

## Consequences

### Positive

- A leaked unused token stops working after a day.

### Negative

- An unused token past 24 hours needs a new one from the admin panel.

### Risks

- A projector replay after the deploy gives old unused tokens their original
  time, so they expire. This is intended.

## Rollout / Migration

A migration adds the column and backfills outstanding tokens with `now()`, so
each gets 24 hours from the deploy. Mention this in the release notes. The
change is backward compatible with old units.

## Confirmation

- `test_expired_provisioning_token_is_rejected_with_the_same_failure_reply` in
  `RegistrationHandlerTest`.
- `test_provisioning_token_past_24_hours_is_refused_and_logged_as_expired` and
  `test_provisioning_token_expires_at_exactly_24_hours` in
  `LockerBankProvisioningResetTest`.
- HW-F8: against a deployed backend, a token older than 24 hours is refused and
  a fresh one works.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#331
- Related issues: #57
