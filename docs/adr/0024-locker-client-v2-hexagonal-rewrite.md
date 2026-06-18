# ADR-0024: locker-client v2 hexagonal rewrite

## Status

Proposed

## Date

2026-06-16

## Context

The existing `locker-client` (~4k LOC) mixes singleton services, overlapping Modbus
monitoring paths, and fragile async concurrency (reconnect storms, stacked
`setInterval` reads). Modbus requests are occasionally lost under load.

The team chose a parallel rewrite in TypeScript (`locker-client-v2/`) with
hexagonal architecture, TDD, and contract tests against `docs/asyncapi/`.

Hard requirements:

- MQTT must self-heal after broker loss and process restart (ADR-0014).
- Compartment open must use Waveshare hardware flash only — never software
  ON/OFF timers (ADR-0004); locks tolerate only brief pulses.

## Decision

1. Build `locker-client-v2/` alongside v1 on branch `feat/locker-client-v2`.
2. Use hexagonal layers: Domain → Application → Ports ← Adapters; wire only in
   `main.ts` / `createApp.ts`.
3. **Modbus:** `LockerBusPort` exposes domain methods (`flashRelay`, …); internal
   `BusActor` uses `p-queue` (concurrency 1, priorities) + `ReconnectCoordinator`
   (single-flight, unlimited retries).
4. **MQTT inbound:** `CommandDispatcher` + per-action `InboundCommandHandler`
   (mirrors backend `AbstractInboundMqttHandler` pattern) with
   `InboundProtocolGuard` (dedup, message_id, transaction_id).
5. **MQTT outbound:** `OutboundMqttPort` always injects `message_id` and
   `timestamp` via `OutboundEnvelope`.
6. **Libraries:** `mqtt`, `modbus-serial`, `serialport`, `zod`, `ajv`, `p-queue`,
   `uuid`, `js-yaml`, `winston`, `node:test`. No DI container.
7. Implementation plan lives in `docs/plans/locker-client-v2-rewrite.md`.

## Alternatives Considered

### Alternative A: In-place refactor of v1

- Pros: no parallel directory
- Cons: singleton mesh hard to untangle; high regression risk
- Why not chosen: clean boundaries cheaper as greenfield with contract tests

### Alternative B: Rust rewrite

- Pros: stronger concurrency guarantees
- Cons: cross-compile setup, team curve, longer delivery
- Why not chosen: concurrency issues are architectural, not Node-specific

### Alternative C: Hand-rolled promise-chain queue (v1 style)

- Pros: no new dependency
- Cons: no built-in priority; reinventing `p-queue`
- Why not chosen: `p-queue` is small, battle-tested

### Alternative D: DI container (tsyringe / inversify)

- Pros: automatic wiring
- Cons: magic, harder to test for ~10 services
- Why not chosen: manual composition root is sufficient

## Consequences

### Positive

- Clear extension points: new MQTT command = new handler; new board = new adapter
- Test suite covers use cases, handlers, BusActor, and AsyncAPI contracts
- v1 remains deployable until feature parity

### Negative

- Temporary duplication of client code and Docker images
- Two clients until cutover PR merges

### Risks

- Feature drift between v1 and v2 during parallel development
- Mitigation: contract tests + cutover checklist in plan doc

## Rollout / Migration

1. Develop on `feat/locker-client-v2`
2. Validate on test Pi (`ghcr.io/open-locker/locker-client:v2`)
3. Merge PR; archive/remove `locker-client/`; set ADR status to `accepted`

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Implementation plan: `docs/plans/locker-client-v2-rewrite.md`
- ADR-0004, ADR-0006, ADR-0007, ADR-0009, ADR-0013, ADR-0014, ADR-0016
- `locker-client/docs/WAVESHARE_INTEGRATION.md`
