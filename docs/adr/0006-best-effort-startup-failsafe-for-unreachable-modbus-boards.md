# ADR-0006: Use a best-effort startup failsafe for unreachable Modbus boards

## Status

Accepted

## Date

2026-03-29

## Context

ADR-0004 introduced a startup failsafe that sends `all relays off` to every
configured Waveshare relay board before normal polling starts.

That strict rule works well when every configured Modbus slave is reachable, but
it causes an operational problem in multi-board setups: if one configured board
is powered off, disconnected, or incorrectly addressed, the startup relay reset
for that single board times out and aborts the whole locker-client process.

This creates a boot loop even when other configured boards are healthy and could
continue to serve locker operations.

The project therefore needs a clearer operability rule for partial Modbus
availability during startup.

## Decision

Open-Locker keeps the startup `all relays off` failsafe, but applies it on a
best-effort basis per configured Modbus board.

The startup behavior is:

- attempt the relay reset for every configured board
- log and remember per-board startup failsafe failures
- continue startup when at least one configured board completed the relay reset
- fail startup only when all configured boards fail the startup failsafe
- start status polling on a reachable board instead of blindly using the first
  configured board

This ADR supersedes the strict startup wording from ADR-0004 that required every
configured board to complete the relay reset successfully before normal startup
could continue.

## Alternatives Considered

### Alternative A: Keep strict fail-fast startup

- Pros:
  - strongest guarantee that every configured board received the startup reset
  - simpler startup state model
- Cons:
  - one offline or misconfigured board crashes the whole client
  - healthy boards become unavailable because of a single bad slave
  - container deployments enter restart loops during partial hardware outages
- Why not chosen:
  - operability is worse than the additional safety benefit in partially
    degraded setups

### Alternative B: Remove the startup failsafe entirely

- Pros:
  - no startup blocking due to relay reset timeouts
  - simplest operational behavior
- Cons:
  - loses the deterministic relay reset after restarts
  - weakens the recovery story documented in ADR-0004
- Why not chosen:
  - the startup relay reset remains valuable and should be preserved for
    reachable boards

## Consequences

- the client can start and operate with a partially reachable Modbus topology
- startup logs now clearly identify boards that skipped the failsafe
- commands targeting an unreachable board still fail individually and must be
  handled at the operation level
- startup safety is slightly weaker for offline boards because they cannot be
  reset until connectivity is restored
- polling is less noisy during startup because it begins on a reachable board

## References

- `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
- `locker-client/src/app.ts`
- `locker-client/src/services/coilPollingService.ts`
