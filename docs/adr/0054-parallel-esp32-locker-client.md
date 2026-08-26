# ADR-0054: Build a parallel ESP32 locker client on ESP-IDF

## Status

Proposed

## Date

2026-08-25

## Context

Open-Locker currently runs a TypeScript/Node.js locker client in Docker on a
Raspberry Pi. It bridges the backend's MQTT contract to Waveshare Modbus RTU
Relay (D) boards and is responsible for safety-critical command deduplication,
hardware-timed relay pulses, compartment state, provisioning, reconnect, and
response recovery.

An ESP32-class implementation could reduce operating-system and deployment
complexity and provide a more appliance-like controller. It cannot be treated as
a direct source-code port: its task model, flash persistence, update mechanism,
security lifecycle, time bootstrap, and failure modes differ materially from a
Linux host.

The Raspberry Pi client remains supported. The backend and broker must serve a
mixed fleet without knowing which implementation controls a locker bank.
AsyncAPI and the shared JSON Schemas are already the authoritative MQTT
contract, and the physical safety boundary already requires the verified
Waveshare hardware flash operation rather than software-timed relay writes.

[ADR-0005](0005-esp-build-controller-board-selection.md) proposed one integrated
ESP32-S3 relay board before the firmware, electrical, and hardware-timed pulse
requirements had been validated. That board remains a useful evaluation
candidate, especially as an isolated RS-485 controller, but its integrated
relays are not yet proven equivalent to the currently required Modbus hardware
flash behavior. A production board decision is therefore premature.

The implementation and validation plan is
[Parallel ESP32 Locker Client Implementation Plan](../plans/esp32-locker-client.md).
Because this ADR is `Proposed`, it records a candidate decision and is not
binding architecture until it is reviewed and accepted. The same applies to any
other proposed decision referenced by the plan.

## Decision

Open-Locker will implement an additional ESP32 locker client in parallel with
the Raspberry Pi client.

The ESP client will:

1. use ESP-IDF as its primary framework, with FreeRTOS tasks, ESP event loops,
   ESP-MQTT, encrypted persistent storage, watchdogs, and signed A/B OTA;
2. implement behavior against the canonical AsyncAPI, JSON Schemas, examples,
   backend handlers, and accepted safety ADRs rather than sharing Pi runtime
   code;
3. preserve existing MQTT topics, payloads, MQTT 3.1.1-compatible session
   behavior, QoS 1, retain flags, message/transaction ID semantics,
   provisioning exchange, ACL model, and response recovery;
4. keep one strictly serialized Modbus RTU worker and reproduce the exact
   Waveshare hardware flash and all-relays-off frames;
5. persist command claims and final responses before and after physical side
   effects so duplicate delivery or reboot cannot intentionally repeat an
   uncertain relay operation;
6. use signed HTTPS OTA with rollback instead of the Pi's
   Docker/Watchtower update path;
7. keep the Raspberry Pi implementation deployable throughout development and
   rollout, with one active controller per locker bank.

Implementation may be coordinated by humans or coding agents in Cursor, Claude
Code, or other tools. Those tools and their worktree/subagent features are
optional execution surfaces; the architecture and verification gates remain
tool-independent.

No production SoC, module, controller board, network medium, or direct-relay
design is selected by this ADR. Hardware selection remains gated by measured
resource use, Ethernet/Wi-Fi needs, UART/RS-485 behavior, trusted-time bootstrap,
flash partition sizing, isolation, power integrity, brownout, EMC/ESD, secure
manufacturing, debugging, and HIL results.

The initial physical parity target remains external Waveshare Modbus RTU Relay
(D) boards over isolated RS-485. Integrated ESP-board relays may be evaluated
later only through a separate safety decision proving hardware-timed pulse
behavior across resets and power failures.

Any required backend, topic, or payload change is outside this decision and
requires a separate ADR plus AsyncAPI/schema migration. No such change is
currently planned.

## Rationale

ESP-IDF exposes the production mechanisms this controller needs directly:
FreeRTOS scheduling and queues, RS-485 UART support, maintained MQTT and Modbus
components, NVS encryption, watchdogs, Secure Boot, Flash Encryption, OTA
rollback, and target test tooling. Those mechanisms are central to the design,
not optional libraries around an Arduino sketch.

Contract-driven parallel development lets the two implementations coexist and
gives the ESP client executable parity targets. It also avoids destabilizing the
Pi client while persistence, OTA, and hardware behavior are validated.

Leaving hardware open prevents an integrated-board convenience from weakening
the accepted hardware-flash safety boundary. It also allows measured comparison
of ESP32-S3, a wired classic ESP32 reference, ESP32-C6 cost-down feasibility, and
ESP32-P4 headroom before production commitment.

## Alternatives Considered

### Alternative A: Replace the Raspberry Pi client with ESP32

- Pros:
  - one client implementation after cutover
  - no long-term mixed-fleet support
- Cons:
  - removes the known rollback path before ESP validation
  - creates a big-bang hardware and firmware migration
  - couples every deployment to unresolved electrical and OTA decisions
- Why not chosen:
  - parallel coexistence is safer and is explicitly required

### Alternative B: Use Arduino as the primary framework

- Pros:
  - low initial prototype friction
  - broad hobbyist library ecosystem
- Cons:
  - obscures bootloader, OTA, security, task, persistence, and driver controls
    that are first-class requirements
  - makes production configuration and ESP-IDF target testing less direct
- Why not chosen:
  - the system needs explicit ESP-IDF lifecycle and safety mechanisms

### Alternative C: Select the ESP32-S3-ETH-8DI-8RO now

- Pros:
  - integrated wide-range power, Ethernet, isolated RS-485, inputs, and relays
  - fast path to a physical prototype
- Cons:
  - integrated relay pulse safety is not proven equivalent to the accepted
    Waveshare Modbus hardware flash command
  - resource, time-bootstrap, OTA, EMC, and production-debug behavior is not yet
    measured
- Why not chosen:
  - it remains a strong evaluation board, but selection must follow evidence

### Alternative D: Reuse the Pi client architecture mechanically

- Pros:
  - visually similar component names and control flow
- Cons:
  - Linux files, processes, Docker, Node promises, and Watchtower do not map to
    MCU flash, tasks, watchdogs, and bootloader slots
  - risks copying mechanisms instead of preserving behavior
- Why not chosen:
  - shared contracts and tests are the correct portability boundary

## Consequences

### Positive

- The backend can support Pi and ESP locker banks through one contract.
- The Raspberry Pi path remains available during development and pilot rollback.
- Safety and idempotency have explicit MCU persistence and power-loss gates.
- Hardware selection is based on measured requirements.
- OTA, boot integrity, flash confidentiality, and watchdog recovery become
  first-class production concerns.

### Negative

- Two client implementations must track future contract changes.
- ESP firmware, electrical hardware, manufacturing, and HIL introduce new
  toolchains and operational responsibilities.
- Behavior that Linux provides through its filesystem, CA store, clock, and
  process supervisor must be designed explicitly.
- Signed OTA and secure manufacturing replace the simpler container update path.

### Risks

- Flash wear or torn writes could weaken command deduplication.
  Mitigation: append-only/versioned records, power-cut testing, bounded
  retention, and fail-closed corruption behavior.
- TLS certificate validation and trustworthy timestamps may be unavailable on
  cold boot.
  Mitigation: resolve RTC/authenticated-time/trust-anchor design in the hardware
  spike before selection.
- An integrated ESP relay could be mistaken for a safe replacement for the
  board-timed flash command.
  Mitigation: external Modbus Relay (D) remains the initial supported actuator.
- Contract drift may emerge between Pi and ESP implementations.
  Mitigation: shared golden vectors, component contract tests, HIL, and a
  feature-to-test traceability manifest.
- Irreversible Secure Boot/Flash Encryption configuration can brick devices or
  block recovery.
  Mitigation: rehearse key custody, fixture, OTA rollback, and fused-device
  recovery before production enablement.

## Rollout / Migration

1. Freeze MQTT and Modbus golden vectors from the Pi implementation.
2. Run hardware/resource/time/persistence/OTA spikes on at least two candidates.
3. Implement host-tested protocol and state-machine modules.
4. Deliver provisioning + heartbeat, then one safe open, then full config/state
   as separate vertical slices.
5. Validate signed OTA, production security, electrical design, HIL fault
   injection, soak, and longevity.
6. Pilot one non-critical locker bank, then progress through controlled canary
   stages.
7. Keep a tested Raspberry Pi rollback kit and never operate Pi and ESP clients
   simultaneously for one locker identity.

Detailed exit criteria and rollback steps are defined in the implementation
plan. Its Phase 0 decisions and the separate OTA ADR are mandatory prerequisites
to implementation, not implicit decisions made by this proposed ADR.

## Supersedes / Superseded By

- Supersedes:
  [ADR-0005](0005-esp-build-controller-board-selection.md), which selected a
  board before the required hardware and firmware validation.
- Superseded by: none.

## References

- Related PRs: none.
- Related issues:
  - [#47](https://github.com/Open-Locker/Open-Locker/issues/47)
- Related docs:
  - [Implementation plan](../plans/esp32-locker-client.md)
  - [Canonical MQTT contract](../asyncapi/mqtt.yaml)
  - [ADR-0002](0002-mqtt-message-id-and-transaction-id-separation.md)
  - [ADR-0004](0004-waveshare-hardware-flash-and-supported-boards.md)
  - [ADR-0014](0014-locker-client-mqtt-session-and-reconnect.md)
  - [ADR-0015](0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md)
  - [ADR-0024](0024-locker-client-v2-hexagonal-rewrite.md)
  - [ADR-0041](0041-locker-client-command-response-recovery.md)
  - [ADR-0046](0046-locker-client-local-persistence-hardening.md)
  - [ADR-0050](0050-per-provisioning-mqtt-credential-identities.md)
