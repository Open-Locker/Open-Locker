# ADR-0031: Contract-aligned locker fleet simulator

> **Renumbered from ADR-0027** — ADR numbers were deduplicated and put in date order from 0018 up; see #214.

## Status

Accepted

## Date

2026-07-25

## Context

Developing and testing the backend, the MQTT contract, mobile/API visibility, and
`locker-client` workflows without physical hardware depends on a simulator that
behaves like a real provisioned locker.

The backend `mqtt:client-simulator` artisan command
(`locker-backend/app/Console/Commands/MqttClientSimulatorCommand.php`) helped
during early MQTT development but has become limited and is drifting from the
canonical contract (issue #82):

- It re-implements device payloads **in PHP**, separate from the real device
  (`locker-client`, TypeScript), so it drifts from the AsyncAPI contract
  (ADR-0015) — the exact problem #82 calls out.
- On `open_compartment` it publishes only a command response. It **never
  publishes a compartment snapshot** on `state/compartments`, so opening a
  compartment does not change `door_state` in the read model and the realtime
  door-state path (ADR-0016/0022) is never exercised.
- It does not publish `state/connection` at all (ADR-0017), has no in-memory
  compartment model, and offers no way to script or manually change a
  compartment's `door_state` (e.g. simulate a door being closed again).
- `message_id` + top-level `timestamp` are present on heartbeats but not on all
  messages; it still emits a legacy `type: command_response` field.
- It emulates exactly **one** locker bank per invocation, so testing several
  banks means juggling several processes.

The canonical contract is already defined: the three lifecycle state topics
`state/heartbeat` (non-retained), `state/compartments` (retained snapshot, keyed
by `compartment_number`) and `state/connection` (non-retained, LWT) per ADR-0017;
retained snapshot and persisted door-state semantics per ADR-0016; `message_id`
vs `transaction_id` separation per ADR-0002; AsyncAPI + JSON Schemas as the
source of truth per ADR-0015. The real device already speaks this contract inside
`locker-client`.

Two properties of the current system shape the decision:

- **`locker-client` v2 is hexagonal** (ADR-0027). Hardware is already isolated
  behind `LockerBusPort`, which is precisely the seam a fake device needs. A
  simulator therefore requires no new abstraction to keep its concerns out of the
  production path — only a second implementation of an existing port.
- **A locker bank provisions exactly once.** `LockerBankAggregate::provision()`
  records `LockerProvisioningFailed` ("Locker bank is already provisioned.") as
  soon as `provisioned_at` is set, and the backend does not retain MQTT passwords
  in plaintext (mosquitto-go-auth verifies via API). Whatever the simulator is
  issued on a bank's first provisioning is the only chance it gets.

## Decision

Build a **contract-aligned fleet simulator inside `locker-client`**, reusing its
contract code path, and retire the backend artisan simulator.

### 1. Location and structure

The simulator is a **second adapter behind an existing port, plus its own
composition root** — not a mode flag threaded through the production boot path:

- `src/ports/locker-bus.port.ts` is the hardware seam. It does not change.
- `src/adapters/modbus/waveshare-modbus-bus-actor.ts` (real hardware) and
  `src/adapters/simulator/in-memory-locker-bus.ts` (fake hardware) are **sibling
  implementations of that one port**. The in-memory bus holds relay and door
  state per compartment and satisfies `flashRelay`, `readDoorSensors`,
  `readRelayState` and `turnAllRelaysOff` from memory.
- Everything above the port — the use cases (`open-compartment`, `apply-config`,
  `state-publishing`), `command-dispatcher`, `outbound-envelope`, `mqtt-schemas`,
  dedup — is **reused unmodified**. This is what makes contract drift
  structurally impossible rather than merely discouraged: the simulator cannot
  emit a payload the real client would not.
- Two composition roots: `src/bootstrap/createApp.ts` (real) and
  `src/bootstrap/createSimulatorApp.ts` (fake bus, N devices), with entrypoints
  `src/main.ts` and `src/main-simulator.ts` (`pnpm sim`).
- **No production code learns the simulator exists.** `createApp.ts` and
  `main.ts` are untouched and there is no `--mode` branch anywhere in the
  production path. Simulator code may remain present in the built image (dead,
  unreachable); physically excluding it is not required.

**Environment guard.** `main-simulator.ts` refuses to start when
`NODE_ENV`/`APP_ENV` is production unless `--allow-production` is passed, so the
simulator cannot accidentally publish fake state for a real bank against the
production broker. One bank is one publisher: never run the simulator and a real
device as the same bank UUID simultaneously, or the broker drops one on session
takeover.

### 2. Per-device state, and the credential cache

`FileCredentialStore`, `FileDedupStore` and `FileRuntimeOverlayStore` resolve
their paths from module-level constants in `src/infrastructure/paths.ts`, so they
are effectively process-global and cannot back several devices at once. The
simulator therefore uses **in-memory stores per simulated device** rather than
refactoring the production stores to take injected paths. This both enables fleet
mode and guarantees the simulator never writes over a real device's `/data`
files.

The one exception is credentials. Because a bank provisions only once, the
credentials issued on that first run must outlive the process or the simulator
would be single-use per bank. A **credential cache keyed by provisioning token**
(`SimulatorCredentialCache`) is consulted before provisioning and written only
after a successful first provision. It lives beside the scenario file (or at
`SIMULATOR_CREDENTIALS_FILE`), never under `DATA_DIR`, is written `0600`, and is
git-ignored. A corrupt cache degrades to a re-provision attempt rather than a
crash.

Losing that cache makes a bank unusable until an administrative provisioning
reset (issue #110) — which is one reason that reset matters for simulator
ergonomics.

### 3. Fleet mode

The simulator emulates **one or many** locker banks in a single process: it
enumerates the banks in the scenario, stands up one simulated device per bank
(each with its own UUID, credentials, client id and subscription to its own
`locker/{uuid}/command`), and drives them from one event loop.

Each device gets its **own instance** of the fake bus, dedup store, credential
holder, config repository and MQTT connection; nothing is shared across devices
except the process and the scenario file. A single-bank run is simply a fleet of
one, so there is no separate single-device code path to maintain. If any device
fails to start, every device already started is shut down — a half-connected
fleet is never left behind.

### 4. Behaviour

Each simulated device:

- subscribes to `locker/{uuid}/command`;
- responds to `open_compartment` with a contract-valid command response and, on
  success, **flips that compartment's `door_state` to `open` and publishes the
  retained snapshot** on `locker/{uuid}/state/compartments` (keyed by
  `compartment_number`), so the door-state read model and realtime broadcast
  update — closing the loop the artisan simulator leaves open;
- responds to `apply_config` with `applied_config_hash`, and adopts the new
  compartment mapping at runtime;
- publishes heartbeats on `state/heartbeat` (non-retained) and registers an LWT
  on `state/connection` for unexpected disconnects;
- publishes the seeded snapshot immediately at startup, so the read model is
  populated without waiting for a command;
- allows **scripted or manual** `door_state` changes that publish a fresh
  retained snapshot.

A door opened by a relay flash stays open until something closes it, because real
doors do not close themselves.

**No `state/connection` "online" message.** Only `state-connection-lost.json`
exists in `docs/asyncapi/schemas/payloads/`, and its `status` is
`const: "offline"`. Publishing an "online" payload would violate the contract
conformance rule below, and the real client does not publish one either —
`connectionLostWillOptions()` registers the LWT and nothing more. The simulator
mirrors the real client. Introducing an online message is a contract change that
belongs in its own ADR, applied to the device and the simulator together.

### 5. Contract conformance

All messages carry `message_id` and a top-level `timestamp`; command responses
carry `transaction_id`; snapshots use `compartment_number` and never backend-only
ids; legacy payloads (`locker/{uuid}/status`, multiplexed `locker/{uuid}/state`)
are not emitted. Payloads validate against the schemas in `docs/asyncapi/schemas`
(`docs/asyncapi/mqtt.yaml`, ADR-0015).

### 6. Configuration and operation

Scenarios are defined in a **YAML scenario file**, matching `locker-client`'s
existing `config/locker-config.yml` convention: which banks to emulate (by
provisioning token), their compartments, and each compartment's initial
`door_state`. YAML keeps multi-bank scenarios repeatable and reviewable. **CLI
flags override runtime basics** (`--scenario`, `--broker`).

Production is runtime-only for the compartment mapping (ADR-0028): it arrives by
`apply_config` and lands in the overlay. The scenario **seeds** that mapping so a
simulated device is useful the moment it boots, while an `apply_config` command
still overrides it at runtime exactly as on real hardware, because the overlay is
consulted first.

An **interactive console** (`list`, `open|close|unknown <bank> <compartment>`) is
included rather than deferred: it costs little on top of the scripted path and is
how door changes are actually driven during development. `--no-interactive`
disables it for non-TTY use.

**Traffic logging is on by default** (`--quiet` disables it). The real client is
deliberately quiet — it runs unattended and logs warnings, not messages — but for
a simulator, watching the traffic is the point, and a developer should not need a
separate MQTT client to see what the fake locker said. The logger wraps the
publish and dispatch seams from outside (`adapters/simulator/traffic-log.ts`), so
production logging behaviour is unchanged.

### 7. Test coverage

Per ADR-0019's component contract suites, tests assert that:

- generated response, heartbeat and snapshot payloads validate against the
  `docs/asyncapi/schemas` JSON Schemas;
- heartbeats and snapshots publish on the **split** state topics with the correct
  retain flags;
- duplicate `transaction_id` commands stay **idempotent** — no second response and
  no second state change;
- legacy topics are never published;
- scenario validation rejects duplicate provisioning tokens, duplicate
  compartment numbers and duplicate relay targets before startup.

Tests exercise the real wiring: `wireSimulatedDevice()` is split from the MQTT
connection so the transport is the only thing a test replaces.

### 8. Retire the backend artisan simulator

`mqtt:client-simulator` is removed once the simulator covers what it did. Keeping
both means two implementations to drift, which is the problem #82 names.

## Alternatives Considered

### Alternative A: Refactor the existing backend artisan command

- Pros: smallest move; stays where developers already run it (`php artisan`).
- Cons: keeps a **second, PHP** implementation of device payloads separate from
  the real device, so drift recurs. Multi-bank and an in-memory model are awkward
  inside a one-shot blocking artisan loop.
- Why not chosen: does not fix the drift; only resets it.

### Alternative B: A standalone, brand-new simulator service

- Pros: clean separation; no coupling to either existing component.
- Cons: a third place that re-implements the contract, with its own drift risk and
  maintenance cost.
- Why not chosen: reusing `locker-client`'s contract code path is the whole point;
  a fresh codebase forfeits it.

### Alternative C: A `--mode simulator` flag on the production entrypoint

- Pros: one entrypoint; no second composition root.
- Cons: puts simulator branches inside the production boot path, where they can be
  reached in production by misconfiguration and must be reasoned about during
  every future change to `createApp.ts`.
- Why not chosen: a second composition root costs a file and buys complete
  isolation.

### Alternative D: Single-bank only (no fleet)

- Pros: simplest runtime; one connection, one loop.
- Cons: testing several banks needs several processes, each with its own data
  directory to avoid the process-global file stores; less representative of a real
  deployment.
- Why not chosen: with per-device instances, a fleet is a loop over the same
  per-device logic, and a single bank is a fleet of one.

### Alternative E: Refactor the production file stores to take injected paths

- Pros: the simulator could reuse `FileCredentialStore` and `FileDedupStore` and
  persist per-device state on disk.
- Cons: changes production infrastructure for a development tool's benefit, and
  invites a simulator run to write where a real device's state lives.
- Why not chosen: in-memory stores give fleet mode without touching production
  code; only credentials genuinely need to survive a run, and they get a
  purpose-built cache outside `DATA_DIR`.

## Consequences

### Positive

- The simulator shares the production contract path, so it stays aligned with the
  AsyncAPI contract by construction (ADR-0015) instead of drifting.
- Opening a compartment drives `door_state` end to end, so the realtime
  door-state path (ADR-0016/0022) is exercisable without hardware.
- One process emulates a whole set of lockers; LWT and manual door changes make
  failure and recovery scenarios testable.
- The existing admin monitor (`connection_status`, `last_heartbeat_at` on the
  Locker banks screen) is driven by simulated devices with no special-casing.
- Production code is untouched, so adopting the simulator carries no risk to the
  real client.

### Negative

- The simulator moves out of `php artisan` into the `locker-client` toolchain
  (Node/pnpm); the backend loses a self-contained command.
- Fleet mode adds a multi-connection lifecycle to manage (connect, reconnect,
  tear down per device).
- The credential cache is a file that must not be lost, which is at odds with the
  otherwise disposable nature of a simulator run.

### Risks

- **Losing the credential cache** strands a bank until an administrative
  provisioning reset (#110). *Mitigation: the cache location is configurable and
  documented; the reset issue is tracked separately.*
- **Simulating a bank whose real device is online** causes broker session
  takeover. *Mitigation: documented; the production environment guard blocks the
  most damaging case.*
- **Parity before removal**: removing the artisan command too early would leave a
  gap. *Mitigation: remove it only once provisioning, commands, snapshots,
  heartbeat and connection/LWT are covered.*

## References

- Related issues:
  - [#82](https://github.com/Open-Locker/Open-Locker/issues/82)
  - [#39](https://github.com/Open-Locker/Open-Locker/issues/39)
  - [#77](https://github.com/Open-Locker/Open-Locker/issues/77)
  - [#110](https://github.com/Open-Locker/Open-Locker/issues/110) (administrative
    provisioning reset)
- Related ADRs:
  - `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
  - `docs/adr/0014-locker-client-mqtt-session-and-reconnect.md`
  - `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
  - `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
  - `docs/adr/0017-split-mqtt-state-topics-by-lifecycle.md`
  - `docs/adr/0018-validate-mqtt-contracts-through-component-test-suites.md`
  - `docs/adr/0023-mobile-realtime-compartment-status-via-reverb.md`
  - `docs/adr/0024-locker-client-v2-hexagonal-rewrite.md`
  - `docs/adr/0026-locker-client-v2-runtime-only-compartment-mapping.md`
- Related contract / docs:
  - `docs/asyncapi/mqtt.yaml`
  - `docs/asyncapi/schemas`
  - `docs/mqtt_integration_plan.md`
  - `docs/simulator.md` (step-by-step guide to running it)
- Related code:
  - `locker-client/src/ports/locker-bus.port.ts` (the seam the fake device implements)
  - `locker-client/src/adapters/modbus/waveshare-modbus-bus-actor.ts` (the real
    implementation the simulator mirrors)
  - `locker-client/src/adapters/simulator/` (fake bus, scenario, credential cache,
    traffic log, in-memory stores)
  - `locker-client/src/bootstrap/createSimulatorApp.ts`,
    `locker-client/src/main-simulator.ts`
  - `locker-client/simulator-scenario.yml.example`
  - `locker-client/tests/simulator/`
  - `locker-backend/app/Console/Commands/MqttClientSimulatorCommand.php` (retired)
