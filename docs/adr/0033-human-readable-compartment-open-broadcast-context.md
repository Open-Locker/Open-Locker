# ADR-0033: Human-readable context in compartment-open broadcasts

## Status

Proposed

## Date

2026-08-11

## Context

`CompartmentOpenStatusUpdated` identifies compartments by UUID and reports
machine-readable status details. This is sufficient for application state, but
Filament notifications need the compartment number and locker-bank name to be
useful to operators. Looking up this context in browser code would require an
additional request for every terminal notification.

The broadcast payload is consumed across an external integration boundary.
Adding fields therefore requires an explicit compatibility and fallback
decision. The underlying compartment or locker-bank read model may also be
missing when an old event is replayed.

## Decision

Add `compartment_number` and `locker_name` to the
`.compartment.open.status.updated` payload.

- Both keys are always present and nullable, preserving compatibility with
  clients that ignore unknown fields.
- The queued broadcast reactor resolves both values from the current read model
  for terminal `opened`, `denied`, and `failed` statuses.
- If the read model is unavailable, both values remain `null`; clients fall
  back to the compartment UUID or a neutral placeholder.
- Denial reasons remain stable machine-readable codes in `message`. Each client
  maps known codes to localized user-facing text and uses a generic denial
  message for unknown codes.

This keeps localization in the receiving client while avoiding an extra network
request for human-readable context. It builds on the private channel and event
contract established by ADR-0016 and ADR-0022.

## Alternatives Considered

### Fetch context after receiving the event

- Benefit: no broadcast payload change.
- Drawback: adds latency, failure modes, and one request per notification.
- Rejected because the queued reactor can resolve the same read model directly.

### Store names and numbers in every domain event

- Benefit: immutable historical display context.
- Drawback: expands several event contracts and requires migration or upcasting
  for historical events.
- Rejected because current operator notifications do not require historical
  snapshots.

### Broadcast translated denial text

- Benefit: clients can display the message without mapping reason codes.
- Drawback: queued reactors do not carry the recipient's active locale, and
  translated text is not a stable integration value.
- Rejected in favor of client-side localization from stable reason codes.

## Consequences

- Filament can show useful terminal notifications without another request.
- Existing consumers remain compatible because the change is additive.
- Consumers must tolerate nullable context and unknown denial reason codes.
- Terminal broadcasts perform one read-model lookup including the locker-bank
  relationship.
- Names reflect the current read model rather than the value at event creation.

Rollout requires deploying the additive backend payload before relying on the
new fields in clients. No data migration is required, and older clients continue
to ignore the additional keys.

## References

- [Issue #165](https://github.com/Open-Locker/Open-Locker/issues/165)
- [PR #178](https://github.com/Open-Locker/Open-Locker/pull/178)
- `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
- `docs/adr/0022-mobile-realtime-compartment-status-via-reverb.md`
- `docs/app_communication.md`
- `locker-backend/app/Events/CompartmentOpenStatusUpdated.php`
- `locker-backend/app/Reactors/CompartmentOpenStatusBroadcastReactor.php`
