# ADR-0027: Contract-aligned locker fleet simulator

## Status

Proposed

## Date

2026-06-24

## Context

Developing and testing the backend, MQTT contract, mobile/API visibility, and
`locker-client` workflows without physical hardware depends on a simulator that
behaves like a real provisioned locker. The current backend
`mqtt:client-simulator` artisan command
(`locker-backend/app/Console/Commands/MqttClientSimulatorCommand.php`) helped
during early MQTT development but is now limited and drifting from the canonical
contract (issue #82):

- It re-implements device payloads **in PHP**, separate from the real device
  (`locker-client`, TypeScript), so it drifts from the AsyncAPI contract
  (ADR-0015) — the exact problem #82 calls out.
- On `open_compartment` it publishes only a `command_response`. It **never
  publishes a compartment snapshot** on `state/compartments`, so opening a
  compartment does not change `door_state` in the read model and the realtime
  door-state path (ADR-0016/0022) is never exercised from the simulator.
- It does not publish `state/connection` (LWT / connection lifecycle, ADR-0017),
  has no in-memory compartment model, and offers no way to script or manually
  change a compartment's `door_state` (e.g. simulate a door being closed again).
- `message_id` + top-level `timestamp` are present on heartbeats but not on all
  messages; payload/topic shapes have drifted from `state-*`/command schemas.
- It emulates exactly **one** locker bank per invocation (one token), so testing
  multiple banks means juggling several processes.

The canonical contract is already defined: the three lifecycle state topics
`state/heartbeat` (non-retained), `state/compartments` (retained snapshot,
keyed by `compartment_number`), and `state/connection` (non-retained, LWT) per
ADR-0017; retained snapshot + persisted door-state semantics per ADR-0016;
`message_id` vs `transaction_id` separation per ADR-0002; AsyncAPI + JSON
Schemas as the source of truth per ADR-0015. The real device path already
speaks this contract inside `locker-client`.

## Decision

Build a **contract-aligned fleet simulator on top of `locker-client`**, reusing
its existing MQTT connection helpers and contract payload builders, and retire
the backend artisan simulator.

1. **Location / reuse.** Implement the simulator as a dedicated entrypoint in the
   `locker-client` repo (e.g. a `simulate` mode) that reuses the same MQTT
   client, topic constants, and message builders the real client uses, and
   swaps the Modbus hardware layer for an **in-memory compartment model**. This
   keeps the simulator on the *same* contract code path as production, which is
   the anti-drift property #82 requires. The backend `mqtt:client-simulator`
   command is **deprecated and removed** once parity is reached.

   **Structure & safety.** The simulator is a **separate module + entrypoint**,
   not tangled into the production boot path:
   - `src/mqtt/` stays the shared contract layer (connection, topics, payload
     builders) consumed by **both** the real client and the simulator.
   - `src/modbus/` (real hardware driver) and a new `src/sim/` (in-memory
     compartment model) are **siblings** — `sim/` is the "fake hardware driver".
   - Two entrypoints: the existing real one (`app.ts`, uses `mqtt` + `modbus`)
     and a new `simulate.ts` (uses `mqtt` + `sim`).
   - **Production runs only the real entrypoint.** The prod image command is
     unchanged; `simulate.ts` is never invoked in production. The sim code may
     remain present in the build (dead, unreachable) — physically excluding it
     from the image is not required and is avoided for build simplicity.
   - **Environment guard:** `simulate.ts` **refuses to start when
     `NODE_ENV`/`APP_ENV` is production** unless an explicit override flag is
     passed, so the simulator cannot accidentally publish fake state for a real
     bank against the production broker. (One bank = one publisher: never run the
     simulator and a real device as the same bank UUID simultaneously, or the
     broker will drop one on session takeover.)

2. **Fleet mode.** The simulator can emulate **one or many** locker banks in a
   single process: enumerate target banks (by provisioning token, or a config
   list), stand up one simulated device per bank (each with its own
   UUID/credentials and a unique client id, subscribed to its own
   `locker/{uuid}/command`), and drive them from one event loop. Per-bank,
   per-compartment `door_state` is held in memory.

3. **Responsive, contract-valid behavior.** Each simulated device:
   - subscribes to `locker/{uuid}/command`;
   - responds to `open_compartment` with a contract-valid command response and,
     on success, **flips that compartment's `door_state` to `open` and publishes
     the retained snapshot** on `locker/{uuid}/state/compartments` (keyed by
     `compartment_number`) so the door-state read model and realtime broadcast
     update — closing the loop that the current simulator leaves open;
   - responds to `apply_config` with `applied_config_hash`;
   - publishes heartbeats on `state/heartbeat` (non-retained) and a
     `state/connection` online message, with an LWT for offline;
   - allows **scripted or manual** `door_state` changes (e.g. mark a compartment
     closed) that publish a fresh retained snapshot.

4. **Contract conformance.** All messages carry `message_id` and top-level
   `timestamp`; command responses carry `transaction_id`; snapshots use
   `compartment_number` (never backend-only ids); legacy payloads
   (`locker/{uuid}/status`, multiplexed `locker/{uuid}/state`) are not emitted.
   Payloads validate against the schemas in `docs/asyncapi/schemas`
   (`docs/asyncapi/mqtt.yaml`, ADR-0015).

5. **Provisioning.** The simulator starts from the **provisioning flow**
   (provision-by-token per bank at startup) rather than relying on stored
   credentials, since the backend does not retain MQTT passwords in plaintext
   (mosquitto-go-auth verifies via API). Once provisioned, each simulated device
   reuses the issued credentials for the rest of the run.

6. **Configuration.** Scenarios are defined in a **YAML scenario file**, matching
   `locker-client`'s existing `config/locker-config.yml` convention: which banks
   to emulate (by provisioning token), their compartments, and initial
   `door_state`s. **CLI flags override runtime basics** (broker host/port,
   scenario file path, a single-bank shortcut). YAML keeps multi-bank scenarios
   repeatable and version-controllable for tests; a live interactive REPL for
   manual door toggles is a possible later add-on, not the primary mechanism.

7. **Test coverage.** Add tests (per ADR-0018's component contract suites) that
   assert: (a) generated response/heartbeat/snapshot payloads validate against
   the `docs/asyncapi/schemas` JSON Schemas; (b) heartbeats and snapshots publish
   on the **split** state topics (`state/heartbeat` vs `state/compartments`) with
   correct retain flags; (c) duplicate `transaction_id` commands stay
   **idempotent** (re-publish the same response, no duplicate state change) —
   preserving the current command's existing dedup behavior.

## Alternatives Considered

### Alternative A: Refactor the existing backend artisan command

- Pros: smallest move; stays where developers already run it (`php artisan`).
- Cons: keeps a **second, PHP** implementation of device payloads separate from
  the real device, so drift recurs — the root cause #82 names. Multi-bank and an
  in-memory model are awkward inside a one-shot blocking artisan loop.
- Why not chosen: does not fix the drift; only resets it.

### Alternative B: A standalone, brand-new simulator service

- Pros: clean separation; no coupling to either existing component.
- Cons: a third place that re-implements the contract, with its own drift risk
  and maintenance cost.
- Why not chosen: reusing `locker-client`'s contract code path is the whole
  point; a fresh codebase forfeits that.

### Alternative C: Single-bank only (no fleet)

- Pros: simplest runtime; one connection, one loop.
- Cons: testing multiple banks/locations needs several processes; less
  representative of a real deployment.
- Why not chosen: fleet mode is a modest scheduler on top of the same per-device
  logic and materially improves dev/test ergonomics.

## Consequences

### Positive

- The simulator shares the production contract path, so it stays aligned with the
  AsyncAPI contract by construction (ADR-0015) instead of drifting.
- Opening a compartment now drives `door_state` end-to-end, so the realtime
  door-state path (ADR-0016/0022) is exercisable without hardware.
- One process can emulate a whole set of lockers; `state/connection`/LWT and
  manual door changes make failure and recovery scenarios testable.

### Negative

- The simulator moves out of `php artisan` into the `locker-client` toolchain
  (Node/pnpm); the backend loses a self-contained command.
- Fleet mode adds a multi-connection scheduler to manage (connect, reconnect,
  tear down per device).

### Risks

- **Credentials for existing banks**: connecting as an already-provisioned bank
  needs its MQTT credentials, which the backend does not retain in plaintext
  (mosquitto-go-auth verifies via API). *Resolved by Decision 5: provision-by-token
  per bank at startup rather than reusing stored passwords.*
- **Parity before removal**: removing the backend command before the new
  simulator reaches feature parity would leave a gap. *Mitigation: keep both
  until the locker-client simulator covers provisioning, commands, snapshots,
  heartbeat, and connection/LWT.*

## References

- Related issues:
  - [#82](https://github.com/Open-Locker/Open-Locker/issues/82)
  - [#39](https://github.com/Open-Locker/Open-Locker/issues/39)
  - [#77](https://github.com/Open-Locker/Open-Locker/issues/77)
- Related ADRs:
  - `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
  - `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
  - `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
  - `docs/adr/0017-split-mqtt-state-topics-by-lifecycle.md`
  - `docs/adr/0014-locker-client-mqtt-session-and-reconnect.md`
  - `docs/adr/0018-validate-mqtt-contracts-through-component-test-suites.md`
  - `docs/adr/0022-mobile-realtime-compartment-status-via-reverb.md`
- Related contract / docs:
  - `docs/asyncapi/mqtt.yaml`
  - `docs/asyncapi/schemas`
  - `docs/mqtt_integration_plan.md`
- Related code:
  - `locker-backend/app/Console/Commands/MqttClientSimulatorCommand.php` (to be retired)
  - `locker-client/src/` (MQTT helpers + contract payloads to reuse)
