# ADR-0049: Locker-client shutdown ordering and async error policy

## Status

Proposed

## Date

2026-08-20

## Context

The locker-client runs one process per locker bank on a Raspberry Pi, in a
container with `restart: unless-stopped`. Stopping it is routine rather than
exceptional: every redeploy, every `docker restart`, every clean reboot sends
SIGTERM and then waits before killing the process.

Shutdown cleared the timers and disconnected. Nothing tracked the commands
already running — the MQTT dispatch promise was discarded — so a redeploy during
an open could fire the relay, drop the transport before the response was
published, and leave the backend's request stuck at `sent` with a door possibly
open and no record of why.

Three surrounding facts shape the decision:

- An MQTT Last Will fires only on an **ungraceful** disconnect. A clean stop
  publishes nothing, so a well-behaved shutdown is less visible to the backend
  than a crash.
- Door-open detection watches for movement after the relay pulse, and its window
  can outlast a whole shutdown.
- A wedged serial port can leave `disconnect()` pending indefinitely.

## Decision

**Shutdown ordering.** Refuse new commands, drain what is already running, clear
timers, flush pending responses, publish an explicit offline state, then
disconnect transport, Modbus bus and tracing in that order.

Timers are cleared **after** the drain, not before, so nothing stops the
monitoring belonging to a command still in progress.

**Refusal is answered, not silent.** Commands arriving after closing begins are
rejected with `SHUTTING_DOWN` so the backend can fail the request immediately
rather than waiting for a timeout. The refusal happens **after** duplicate
detection, so a redelivery of a command that already completed still replays its
stored response — answering it with an error would contradict a reply the
backend may have acted on.

**Closing state is read at command arrival**, not after parsing, so a command
that reached the client before shutdown began is not refused mid-parse.

**Door detection is not awaited.** The relay pulse completes and the
`acknowledged` response is published; a detection that never lands is covered by
the backend's own timeout.

**Explicit offline state.** A clean stop publishes `status: offline`,
`reason: shutdown` on `locker/{uuid}/state/connection`, alongside the Last Will
that covers the ungraceful case.

**Each teardown step may be abandoned.** A step that hangs or throws is logged
and the sequence continues.

**`stop_grace_period: 60s`** in compose, so Docker's 10s default cannot kill the
process mid-drain.

**Async error policy**, stated explicitly:

| Class | Example | Handling |
| --- | --- | --- |
| Transient | a publish that failed, a poll that errored | log and continue; retried on the next tick |
| Terminal | unhandled rejection, uncaught exception | log and exit non-zero; Docker restarts clean |
| Stuck teardown | `disconnect()` that never settles | abandon that step, continue the sequence |

## Rationale

Finishing in-flight work is preferred over abandoning it because an unreported
open is worse than a slow stop: the door may be physically open while the record
says the command never completed. That is the same conflation of "command sent"
with "door opened" that the command-acknowledgement split removed, reappearing
as a timing gap.

Refusing rather than dropping turns an invisible failure into a visible one. The
backend already distinguishes failure causes by status and error code; silence is
the one case it cannot attribute.

A process driving physical locks should not continue in an unknown state, which
is why terminal errors exit rather than log-and-hope. Transient publish failures
are the opposite: killing a bank because one heartbeat did not land trades a
minor fault for an outage.

## Alternatives Considered

### Abandon in-flight work immediately on SIGTERM

Simplest and fastest. Rejected: it guarantees the failure mode this change
exists to remove — a fired relay with no response.

### An overall shutdown time budget instead of per-step abandonment

A single deadline for the whole sequence. Rejected in favour of per-step
abandonment, which lets every step run rather than cutting the sequence short at
an arbitrary point. The consequence is recorded below.

### Rely on the Last Will alone

No explicit offline publish. Rejected: a will does not fire on a clean
disconnect, so the most common stop would be the least visible.

### Rely on the heartbeat timeout to notice a stopped bank

Already the fallback. Rejected as the only mechanism: it reports a bank as online
for as long as the timeout allows, after the client has deliberately said
goodbye.

## Consequences

### Positive

- A redeploy no longer strands a command mid-open.
- The backend is told why a command was refused instead of waiting for a timeout.
- A clean stop is at least as visible as a crash.
- One stuck teardown step cannot strand the rest of the sequence.

### Negative / Risks

- **The drain is unbounded.** Awaiting in-flight commands has no deadline, so a
  command that never settles ends at SIGKILL after `stop_grace_period`. Per-step
  abandonment covers teardown, not the drain.
- A detection abandoned at shutdown surfaces as a backend timeout rather than a
  definite outcome.
- The explicit offline publish is currently groundwork:
  `LockerConnectionStateHandler` validates and logs but does not yet write
  `connection_status`, which remains driven by the heartbeat sweep.
- `stop_grace_period: 60s` lengthens the worst-case redeploy.

## References

- Issue #170 — Make locker-client shutdown and async lifecycle safe
- `locker-client/src/bootstrap/createApp.ts`, `src/main.ts`,
  `src/adapters/mqtt/command-dispatcher.ts`
- `locker-client/docker-compose.yml`
