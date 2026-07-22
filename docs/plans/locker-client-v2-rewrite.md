# locker-client v2 — Implementation Plan

Living document for the parallel rewrite. Architecture decision: [ADR-0024](../adr/0024-locker-client-v2-hexagonal-rewrite.md).

## Branch

`feat/locker-client-v2` — implemented in parallel and cut over to `locker-client/`.

## Phases

### Phase 0 — Fundament

- [x] ADR-0024
- [x] This plan
- [x] `locker-client/` scaffold (package.json, TS strict, Dockerfile)
- [x] TDD: OutboundEnvelope, InboundProtocolGuard, BusActor
- [x] Migrate AsyncAPI contract tests from v1

### Phase 1 — Modbus + Domain

- [x] Domain models + flash duration validation (100–500ms)
- [x] OpenCompartmentUseCase (hardware flash only)
- [x] PollCompartmentStateUseCase (ADR-0007 sequential)
- [x] Startup all-relays-off failsafe (ADR-0006)

### Phase 2 — MQTT

- [x] CommandDispatcher + handlers (open_compartment, apply_config)
- [x] MqttTransportAdapter (ADR-0014 unlimited reconnect)
- [x] Heartbeat + compartment snapshots (ADR-0016)

### Phase 3 — Config & Provisioning

- [x] YAML + runtime overlay (ADR-0009)
- [x] Provisioning flow (ADR-0013/0014)
- [x] apply_config with rollback

### Phase 4 — Cutover

- [x] Feature parity checklist (`locker-client/CUTOVER.md`)
- [ ] Pi hardware test (manual)
- [x] Replace v1 with the rewrite at `locker-client/`

## Hard requirements

1. **MQTT reconnect:** `clean: false`, unlimited retries, no `client.end()` on failure.
2. **Lock safety:** Waveshare flash-on only; dedup; startup all-relays-off.

## Test layout

```
locker-client/tests/
├── contract/     # AsyncAPI schema validation
├── unit/         # BusActor, envelope, guard, domain
├── application/  # use cases with fake ports
└── handlers/     # one file per inbound handler
```

## Fallow evaluation

**Decision: reject for runtime use.** Do not integrate [fallow-rs/fallow](https://github.com/fallow-rs/fallow) into the locker-client service.

### What Fallow is

Fallow is a **Rust-native static analysis / codebase intelligence CLI** for TypeScript and JavaScript. It is distributed via npm (`fallow`, `@fallow-cli/fallow-node`) but is **not** a runtime library. It analyzes repos at build/CI time for:

- dead code, unused exports/dependencies
- duplication (clone detection)
- complexity hotspots and health scores
- architecture boundary violations (presets include `hexagonal`, `layered`, `bulletproof`)
- optional paid runtime coverage intelligence (production hot/cold paths)

It is **not** an actor framework, message bus, MQTT router, or Modbus concurrency primitive. The `fallow-rs` org name refers to the Rust implementation of the analyzer, not a Rust rewrite of the edge client.

### Fit vs locker-client pain points

| Pain point | Current v2 approach | Would Fallow help? |
| --- | --- | --- |
| Modbus request serialization | `WaveshareModbusBusActor` + `p-queue` concurrency 1 + `BusPriority` | **No** — Fallow does not execute or queue Modbus operations |
| Reconnect storms / single-flight | `ReconnectCoordinator` inside `BusActor` | **No** — no runtime networking |
| MQTT command routing | `CommandDispatcher` + per-action `InboundCommandHandler` + `InboundProtocolGuard` | **No** — not a message router; plan already rejects generic MQTT router packages for different reasons |
| Hexagonal layer discipline | Manual ports/adapters + ADR-0024 | **Partial (CI only)** — `boundaries.preset: "hexagonal"` could gate import violations in PRs, but does not replace runtime design |
| Lost requests under load | Priority queue + serialized bus actor | **No** |

`p-queue` + `WaveshareModbusBusActor` directly address ADR-0024's concurrency requirements. Fallow operates in a completely orthogonal layer (developer tooling).

### Integration cost

| Factor | Assessment |
| --- | --- |
| Language | Analyzer core is Rust (~90%); npm wrapper shells out to native binary — **not embeddable as in-process runtime logic** |
| Pi / ARM deployment | Production image would need an extra native binary or dev-only CI install; **no benefit on the Pi runtime path** |
| Team stack | TypeScript service; adding Rust tooling to the **runtime** path conflicts with ADR-0024 (explicit TS rewrite, rejected Rust rewrite for delivery reasons) |
| Maturity | Mature OSS (3.7k+ stars, active releases, MIT); **mature as a linter, irrelevant as a bus actor** |
| Operational footprint | One-shot CLI, not a long-running service — correct for CI, wrong for Modbus/MQTT orchestration |

### Tradeoffs summary

| Option | Pros | Cons |
| --- | --- | --- |
| **Reject (chosen)** | Keeps runtime deps minimal per ADR-0024; no confusion between static analysis and hardware bridge; `p-queue` + `BusActor` already solve serialization | No automated boundary enforcement in CI |
| Runtime integration | — | Category error; Fallow cannot serialize Modbus or route MQTT |
| Dev-only CI (`fallow audit` + hexagonal preset) | Could catch `domain → adapters` import leaks early; fast, zero-config | Extra devDependency; syntactic-only (no types); team must triage false positives; **out of scope for edge runtime** |

### Recommendation

- **Runtime:** **No.** Continue with `WaveshareModbusBusActor` + `p-queue` + `CommandDispatcher` as decided in ADR-0024.
- **Optional follow-up (separate task):** Keep `fallow` as a `devDependency` in `locker-client` with `.fallowrc.json` `boundaries.preset: "hexagonal"` for CI `fallow audit` on PRs. That is quality tooling, not a substitute for the bus actor.

## Agent hook

New sessions: read this file + ADR-0024 before editing `locker-client/`.
