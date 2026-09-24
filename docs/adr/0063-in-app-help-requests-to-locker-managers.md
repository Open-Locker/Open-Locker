# ADR-0063: In-app help requests to locker managers

## Status

Proposed

## Date

2026-09-24

## Context

When a compartment does not open, the mobile app now shows why (door did not
open, failed, locker not responding) and lets the user retry. A user whose
retry also fails is standing at a closed locker with their belongings inside
and no way forward in the app (#252).

Operators already hear about jams: `CompartmentOpenDeviationAlertReactor`
sends everyone holding `compartment.open` a live panel toast and an email. That
alert says which compartment misbehaved, not who is waiting at it, and gives
the user no channel to the operator.

Two ways to reach an operator were requested together: calling them, and
sending them a message. Calling needs only a number per bank
(`locker_banks.support_phone`, added in the same change). Sending a message
needs an API endpoint, a record of the request, and a notification.

## Decision

After two failed open attempts in a row on a compartment, the app offers a
**Get help** screen with a Call button (the bank's `support_phone`, if set) and
a message form.

`POST /api/compartments/{compartment}/help-requests` with `{ message }`
(1–1000 characters, trimmed) answers `202 { status, help_request_id }`.

- Only users with active access to the compartment, or who may manage access,
  can send one; others get 403. The access rule is the content note's.
- The route is throttled to 5 requests per 10 minutes per user, since every
  request mails every operator.
- The request is recorded as a `CompartmentHelpRequested` stored event through
  its own `CompartmentHelpRequestAggregate`, keyed by a fresh UUID per request
  like an open command.
- `CompartmentHelpRequestAlertReactor` notifies the same operators as the
  deviation alert, over the same channels: a live panel toast and an email.
  The email's Reply-To is the requesting user, so the operator can answer
  directly.
- The audit log lists the event under *access*, including the message.

## Rationale

Recording the request as an event follows the backend's rule that domain facts
go through aggregates, and gives operators a durable record in the audit log
when an email is missed. Reusing the deviation alert's recipients and channels
means operators learn about help requests where they already watch for locker
problems, without new settings.

## Alternatives Considered

### Send only, store nothing

- Pros: no user-written text in the permanent event store
- Cons: no audit record; a lost email loses the request entirely
- Why not chosen: the request is the only trace that a user was stuck

### Call button only

- Pros: no endpoint, no stored text
- Cons: useless outside office hours or when no number is set
- Why not chosen: both options were requested together

### Include the user in the jam alert instead

- Pros: tiny change, no new endpoint
- Cons: only covers jams, and the user still cannot say anything
- Why not chosen: does not give the user a channel; still worth doing separately

## Consequences

### Positive

- a stuck user has a way forward inside the app
- operators get who, where, and what in one message they can reply to
- every request is auditable

### Negative

- the user's free-text message stays in `stored_events` permanently, alongside
  the audit trail #272 decided to keep unchanged
- every request mails every operator; large operator groups get noise

### Risks

- users may write personal data into the message; it cannot be erased from the
  event store under the current #272 constraint
- no operator holding `compartment.open` means nobody is notified (logged as a
  warning, as for deviations)

## Rollout / Migration

No data migration. The mobile client is regenerated from the live spec.

## Supersedes / Superseded By

- None

## References

- #252, #272
- ADR-0023 (mobile realtime compartment status)
- ADR-0045 (open status broadcast context)
- `locker-backend/app/Reactors/CompartmentOpenDeviationAlertReactor.php`
