# Parallel ESP32 Locker Client Implementation Plan

Status: implementation-oriented plan
Last updated: 2026-08-26
Decision record: [ADR-0054](../adr/0054-parallel-esp32-locker-client.md)

## 1. Goal and non-goals

Build a second locker client for ESP32-class hardware while retaining the
Raspberry Pi client in `locker-client/`. The ESP implementation must present the
same externally observable locker behavior and use the same backend, MQTT,
AsyncAPI, and Modbus hardware contracts unless a separately approved contract
change is unavoidable.

The evaluation tests two product hypotheses rather than assuming them:

1. An ESP controller may be more stable in cabinet-side operation because it
   has lower system complexity and a smaller operating-system, deployment, and
   maintenance surface than a Linux-based Raspberry Pi client.
2. ESP hardware may have a lower and more predictable acquisition cost and
   better availability than Raspberry Pi hardware during periods of elevated Pi
   pricing.

Neither hypothesis is proven. The pilot must compare both implementations using
the same workload and explicit cost, availability, energy, operational, failure,
and recovery measures. The Raspberry Pi client remains a supported parallel
alternative regardless of whether the ESP pilot proceeds.

The work is not a port of Node.js APIs. It is a behavioral reimplementation
against these sources of truth:

1. `docs/asyncapi/mqtt.yaml` and its JSON Schemas and examples;
2. accepted ADRs governing MQTT, provisioning, persistence, and Modbus safety;
3. backend publishers, handlers, authentication, and ACL behavior;
4. observable behavior of the production Raspberry Pi client.

Non-goals for the first production-capable ESP release:

- replacing or retiring the Raspberry Pi client;
- changing backend endpoints, MQTT topics, or payload schemas;
- adding Modbus TCP, which the current Pi client does not implement;
- using generic software-timed relay ON/OFF sequences;
- selecting production hardware before electrical and firmware validation;
- providing a generic multi-board or multi-protocol framework.

## 2. Current system baseline

### 2.1 Runtime and deployment

The current client is a TypeScript/Node.js 22 service deployed as a Docker image
on Raspberry Pi. Docker Compose:

- maps `/dev/ttyACM0`;
- mounts read-only operator config at `/config`;
- mounts writable persistent state at `/data`;
- restarts the client unless stopped;
- allows 60 seconds for graceful shutdown;
- rotates local JSON logs;
- uses Watchtower to poll and install labeled GHCR image updates.

The production entrypoint takes a non-blocking Linux `flock` so only one process
writes a given data directory. The process exits on uncaught exceptions and
unhandled rejections, allowing Docker to restart it.

### 2.2 Provisioning and identity

First boot requires:

- a stable client ID, generated as `locker-client-<8 hex chars>` unless
  `MQTT_CLIENT_ID` is configured;
- `MQTT_BROKER_URL`, production default
  `mqtts://open-locker.cloud:8883`;
- shared bootstrap MQTT username/password;
- a one-time backend-issued `PROVISIONING_TOKEN`.

The client connects with bootstrap credentials, subscribes at QoS 1 to
`locker/provisioning/reply/{client_id}`, and publishes at QoS 1 to
`locker/register/{token}`:

```json
{
  "client_id": "locker-client-a1b2c3d4",
  "message_id": "<uuid>",
  "timestamp": "<ISO-8601>"
}
```

It waits 30 seconds for a schema-valid reply, stores `mqtt_user`,
`mqtt_password`, and `locker_uuid`, disconnects, waits five seconds, and
reconnects with the device identity. The MQTT username is opaque and is used
only for authentication; `locker_uuid` is the topic namespace. Older credential
files fall back to using the username as the UUID.

The backend stores provisioning tokens only as one-time HMACs, issues opaque
per-provisioning MQTT usernames, maps each username to a locker bank for ACL
checks, and permanently disables revoked identities. Re-provisioning creates a
new broker session identity. The registration topic contains a secret and must
never be logged.

### 2.3 MQTT transport behavior

The Pi client uses MQTT 3.1.1-compatible behavior through mqtt.js:

- stable persisted client ID;
- persistent session by default (`clean: false`);
- QoS 1 for command subscriptions and every application publish;
- 60-second keepalive;
- 30-second initial connection timeout;
- reconnect every five seconds;
- unlimited MQTT reconnect by default;
- broker certificate-chain and hostname verification for `mqtts://`;
- plaintext MQTT allowed only for explicit local development;
- non-retained Last Will on
  `locker/{uuid}/state/connection`;
- Last Will body: `status=offline`, `reason=mqtt_last_will`;
- explicit non-retained `status=offline`, `reason=shutdown` on graceful stop.

The current transport does not implement an application backoff curve; mqtt.js
uses the configured fixed reconnect period. Pending command responses are
flushed after every successful connection.

Canonical device topics are:

| Direction | Topic | QoS | Retain |
| --- | --- | ---: | --- |
| Backend → device | `locker/{uuid}/command` | 1 | no |
| Device → backend | `locker/{uuid}/response` | 1 | no |
| Device → backend | `locker/{uuid}/event` | 1 | no |
| Device → backend | `locker/{uuid}/state/heartbeat` | 1 | no |
| Device → backend | `locker/{uuid}/state/compartments` | 1 | yes |
| Device → backend | `locker/{uuid}/state/connection` | 1 | no |

The formal contract does not currently document MQTT message expiry. With a
persistent session, an old QoS 1 command may therefore be delivered after a long
offline period. The Pi client does not reject commands based on timestamp age.
This is an existing product risk, not an ESP-specific policy to change silently.

### 2.4 Commands, IDs, and recovery

Supported commands are:

- `open_compartment`;
- `apply_config`.

Every MQTT message requires a technical `message_id`. Commands and responses
also require a stable business `transaction_id`. QoS 1 can redeliver messages,
so both layers matter:

- `message_id` prevents processing one publication twice;
- `transaction_id` prevents one physical command from executing twice.

The dispatcher:

1. parses JSON and resolves the action;
2. validates required IDs and the action schema;
3. records a previously unseen message ID;
4. synchronously claims a transaction as `in_progress`;
5. executes the handler;
6. stores the complete semantic response as `completed`;
7. publishes it;
8. records delivery only after the QoS 1 publish callback succeeds.

Duplicate completed commands replay the stored response with the original
transaction ID and a new message ID. Duplicate in-progress commands do not
execute again. After restart, an `in_progress` command is not retried because its
physical outcome is unknown; it becomes a pending `UNKNOWN_ERROR` response.
Undelivered final responses are retried after reconnect and restart.

The implementation retains seen message IDs for 30 days, capped at 10,000.
Delivered command responses are retained for 30 days. Pending, interrupted, and
legacy outcome-unknown records have no automatic expiry.

### 2.5 Configuration

The operator-managed YAML contains Modbus bus settings and optional MQTT
transport settings. Backend-owned runtime configuration is a separate persisted
overlay:

- heartbeat interval;
- compartment number → Modbus slave ID and address mapping;
- applied SHA-256 configuration hash;
- update timestamp.

`apply_config` validates:

- positive integer heartbeat interval;
- compartment number, slave ID, and address;
- relay address in `0..7`;
- unique compartment numbers;
- unique `(slaveId, address)` targets;
- SHA-256 hash of normalized mappings.

The client writes the new overlay, reloads services, reconnects/reloads the bus
if necessary, forces a snapshot, and rolls back to the previous overlay if any
step fails. Before the first valid runtime overlay, open commands fail with
`RUNTIME_CONFIG_NOT_APPLIED` and no compartment snapshot is published.

### 2.6 Modbus and physical behavior

The implemented transport is Modbus RTU over one serial port. There is no
Modbus TCP adapter in the current client.

Current serial defaults:

- 9600 baud;
- 8 data bits;
- no parity;
- 1 stop bit;
- 1000 ms response timeout.

All Modbus work is serialized through one priority queue:

1. open commands;
2. snapshots and polling;
3. maintenance and reconnect work.

The driver enforces an RTU inter-frame gap before every transaction after the
first. At or below 19200 baud it uses 3.5 character times; above 19200 baud it
uses 1.75 ms. It adds a 1 ms timer margin and rounds up, resulting in 5 ms at
9600/8N1.

Only the verified Waveshare Modbus RTU Relay (D) protocol is supported:

| Operation | Function | Address/value |
| --- | --- | --- |
| Read relay state | FC01 | `0x0000..0x0007` |
| Read digital input | FC02 | `0x0000..0x0007` |
| Timed release pulse | FC05 | `0x0200 + address`, duration in 100 ms steps |
| All relays off | FC05 | address `0x00ff`, value `0x0000` |

Pulse duration is constrained to 100–500 ms and rounded up to 100 ms steps. The
relay board, not the client scheduler, times the pulse. This safety property must
not be replaced by an ESP task delay around generic relay ON/OFF writes.

At startup, the client attempts `all relays off` for configured boards. A bus
already declared unreachable is logged and startup continues. A reachable bus
where every configured board remains silent fails startup. Partial board failure
does not prevent healthy boards from serving.

Recoverable serial errors trigger disconnect, a bounded five-attempt reconnect
cycle, one retry of the operation, and a 60-second cooldown before another
cycle. A spent cycle sets the bus to `unreachable`; polling later starts a new
cycle. The error-code list includes `ENOENT`, `ENXIO`, `EIO`, `EBADF`, and
`ECONNREFUSED`, plus the library's `Port Not Open` condition.

### 2.7 State, events, and timing

The client polls digital inputs every 500 ms. It performs one contiguous FC02
read per configured slave, serially across slaves. Overlapping ordinary polls
are skipped; a force request during a poll produces one follow-up poll.

Door input mapping is active-low:

- input `true` → `closed`;
- input `false` → `open`;
- missing/error → `unknown`.

A known state survives the first two consecutive unknown reads. The third
publishes `unknown`. Snapshots contain all configured compartments, are sorted
by compartment number, retained, and are sent initially, after explicit force,
and whenever the effective vector changes.

Heartbeat starts immediately after hardware initialization and defaults to 15
seconds unless runtime config overrides it. It includes:

- `message_id`;
- `timestamp`;
- uptime since process start;
- `modbus_connected`, true only in the connected bus state.

An open command first reads the door, sends the hardware-timed relay pulse, and
acknowledges command execution without waiting for physical door movement.
Door detection continues every 500 ms for one heartbeat interval and emits:

- `compartment_open_detected` with `opened` or `already_open`;
- `compartment_open_failed` with `door_jammed` and `DOOR_JAMMED`;
- `compartment_uncommanded_open` for an unexplained closed→open transition.

### 2.8 Persistence, security, and observability

The Pi stores four owner-only files:

- MQTT client ID;
- provisioned MQTT credentials and locker UUID;
- runtime config overlay;
- deduplication, command outcome, and pending-response state.

Writes use same-directory temporary files, file flush, atomic rename, and
best-effort directory flush. Corruption is fail-closed and never auto-replaced.

Logging is structured Winston output to console. Optional OpenTelemetry exports
logs and explicit MQTT/Modbus spans over OTLP. `traceparent` is an optional
top-level MQTT payload property because the backend MQTT library is limited to
MQTT 3.1/3.1.1. Heartbeats and snapshots deliberately do not create spans.
Secrets and registration topic values are redacted.

### 2.9 Existing test coverage

The client has:

- unit tests for parsing, envelopes, dedup, persistence, reconnect, transport,
  config, shutdown, logging, and Modbus behavior;
- application and handler tests through fake ports;
- JSON Schema and AsyncAPI syntax/example tests;
- implementation-built MQTT payload contract tests;
- simulator tests using the production application/dispatcher path;
- an opt-in real-broker revocation integration test.

Missing or not proven by the repository:

- production Raspberry Pi hardware soak completion remains unchecked;
- the documented serial reconnect codes still require real unplug testing;
- no automated HIL rig is present;
- no brownout, filesystem corruption, or power-interruption campaign exists;
- no measurable fleet SLOs or longevity gates are defined.

## 3. Contract baseline and explicit deviations

### 3.1 Required parity baseline

The ESP client must consume the same committed AsyncAPI examples and JSON
Schemas as the Pi client. Golden vectors must cover byte-level semantic
equivalence after normalizing generated `message_id`, `timestamp`, and optional
`traceparent`.

The first production release targets MQTT 3.1.1, not MQTT 5, because the backend
library currently supports only MQTT 3.1/3.1.1. It must use the existing topic
names, payload fields, QoS 1, and retain flags.

### 3.2 Planned contract deviations

None.

The following are implementation/deployment differences, not backend or MQTT
contract changes:

- ESP firmware is updated through signed HTTPS OTA with A/B rollback instead of
  Docker/Watchtower;
- local config and state use flash partitions instead of YAML and JSON files;
- V1 local commissioning uses a temporary SoftAP to provide Wi-Fi and bootstrap
  inputs; a USB fixture may remain available for manufacturing and recovery;
- local diagnostics use UART/JTAG and a bounded flash log/crash store rather
  than `docker logs`.

Any discovery requiring a new MQTT topic, payload field, provisioning exchange,
or backend endpoint must stop at an ADR + AsyncAPI proposal. It must not be
folded into the ESP implementation as an undocumented exception.

## 4. Framework recommendation

Use ESP-IDF, pinned to a tested stable release line, with C or constrained C++.
At the time of research, the current stable documentation is ESP-IDF 6.0.2.

Reasons:

- ESP-IDF is Espressif's production framework and directly exposes FreeRTOS,
  `esp_event`, UART RS-485 modes, ESP-Modbus v2, ESP-MQTT, NVS, watchdogs,
  Secure Boot, Flash Encryption, OTA rollback, crash dumps, and target testing;
- security and OTA lifecycle options are Kconfig- and bootloader-level
  decisions, not Arduino-library add-ons;
- deterministic task ownership and static memory budgeting are important for a
  serialized physical bus and durable command state;
- ESP-IDF's Unity and pytest-embedded tooling supports host-controlled target
  and HIL tests.

Arduino as the primary framework is not recommended. Arduino as an ESP-IDF
component may be used only for a narrowly justified peripheral library after
measuring footprint and ensuring it does not own networking, MQTT, OTA,
persistence, or Modbus scheduling.

## 5. Hardware selection

Version 1 is Wi-Fi-only. It uses a temporary SoftAP for local commissioning and
then joins the configured infrastructure Wi-Fi network. Ethernet is deferred to
a later version and is not part of the V1 implementation or acceptance scope.
Candidate selection should nevertheless avoid unnecessary barriers to a future
Ethernet-capable board variant.

### 5.1 Selection criteria

Do not approve a chip or board from CPU frequency alone. Prototype candidates
must be scored against:

**Compute and memory**

- enough internal SRAM for TLS, MQTT QoS 1 buffers, JSON parsing, Modbus, OTA,
  diagnostics, and worst-case concurrent task stacks;
- at least 8 MB flash for dual OTA slots plus NVS/journal/crash partitions;
- 16 MB flash preferred until measured partition sizing is complete;
- PSRAM is useful for diagnostics and TLS buffers but safety state and DMA
  buffers must not depend on it.

**Interfaces**

- one dedicated UART with TX/RX/RTS for half-duplex RS-485;
- separate UART or native USB Serial/JTAG for production diagnostics;
- integrated Wi-Fi with antenna performance suitable for the intended metal
  cabinet and installation environment;
- enough non-strapping GPIO after flash/PSRAM, USB/JTAG, reset, status, and
  service inputs are allocated;
- a future board variant may add Ethernet without changing application,
  MQTT, provisioning, or Modbus contracts;
- hardware RNG and supported Secure Boot v2/Flash Encryption.

**Network**

- stable Wi-Fi association, DHCP, reconnect, roaming, and credential-recovery
  behavior;
- a bounded, authenticated SoftAP commissioning mode that is disabled after
  successful setup;
- certificate bundle or pinned trust-anchor support;
- a workable cold-boot time bootstrap for X.509 validation.

**Lifecycle**

- module availability and documented longevity;
- comparable bill of materials (BOM) and total cost of ownership (TCO) for the
  same locker-bank capability, including controller, storage, power supply,
  enclosure, commissioning, spares, updates, and operator time;
- quoted acquisition price from at least two viable suppliers, price variance
  over the evaluation window, current stock, minimum order quantity, and quoted
  lead time;
- measured idle, typical, and peak power consumption translated into annual
  energy use at the intended duty cycle;
- measured commissioning, update, incident-recovery, and routine-maintenance
  effort using the same operational scenarios as the Pi baseline;
- observed failure rate and recovery behavior under equivalent network, broker,
  bus, power, storage, and update faults;
- production-programming fixture support;
- secure key/eFuse provisioning;
- accessible UART and JTAG test pads with a production lock-down policy;
- reset-reason and brownout diagnostics.

### 5.2 Recommended shortlist

| Candidate | Strengths | Material risks | Prototype role |
| --- | --- | --- | --- |
| ESP32-S3 module with PSRAM | Dual core, integrated Wi-Fi, three UARTs, USB Serial/JTAG and USB OTG, PSRAM options, mature ESP-IDF support | Antenna placement and coexistence with cabinet electronics require measurement | Primary V1 firmware and custom/industrial-board evaluation |
| ESP32-S3-ETH-8DI-8RO-class industrial board | Existing 7–36 V input, Wi-Fi, Ethernet, isolated RS-485, digital isolation, inputs, service USB, optional PoE variants | Board variants and schematics must be verified; onboard relays are not proven to provide the required hardware-timed 100–500 ms flash semantics | Fast V1 HIL prototype over Wi-Fi; Ethernet remains disabled/deferred |
| ESP32-C6 | Wi-Fi 6, RISC-V, modern security, low cost | Single core, no PSRAM, only two main UARTs, tighter RAM/GPIO budget | V1 cost-down feasibility only after full-stack memory profiling |
| ESP32-WROOM-32E + RMII PHY / ESP32-Ethernet-Kit reference | Integrated Wi-Fi and 10/100 EMAC, three UARTs, well-understood wired gateway reference | Older generation; fixed RMII pins and GPIO0 clock/strapping constraints; security capability depends on chip revision | Later Ethernet compatibility reference, not a V1 target |
| ESP32-P4 + RMII PHY | Integrated EMAC, high performance, abundant SRAM/GPIO, strong diagnostics headroom | No integrated Wi-Fi, likely oversized and higher BOM/complexity; PSRAM/RMII clock interactions require care | Later Ethernet-only reference, not eligible for V1 |

The shortlist intentionally does not choose a winner. Phase 1 must produce
measured flash, internal SRAM, peak heap, task stack high-water marks, TLS
handshake headroom, Modbus timing, Wi-Fi/SoftAP stability, boot time, and power
transient results for at least the S3 primary and one cost-down candidate.

### 5.3 Electrical requirements

For any custom or selected board:

- use a galvanically isolated RS-485 transceiver or an isolated RS-485 module;
- expose controllable DE/RE and verify ESP-IDF half-duplex RTS polarity/timing;
- provide switchable 120 Ω termination only at physical bus ends;
- provide defined bias/polarization consistent with the installed bus;
- include TVS protection appropriate to the RS-485 common-mode environment;
- use a shared reference conductor where required by the Modbus serial-line
  installation, without defeating the chosen isolation boundary;
- separate relay/lock power transients from MCU rails;
- specify input reverse-polarity, surge, over-current, and ESD protection;
- validate 7–36 V or selected cabinet input across load dump and relay events;
- size local capacitance and regulator transient response to avoid brownouts;
- route the Wi-Fi antenna according to vendor guidance and preserve applicable
  layout constraints if a later board variant adds Ethernet magnetics;
- expose reset, boot, UART, and JTAG pads for fixtures;
- define how debug access is disabled or authenticated in production;
- consider an external supervisor/watchdog only after testing the internal
  watchdog and brownout detector against regulator and firmware failure modes.

The integrated Waveshare ESP relays must not drive locker releases in the first
parity build. Keep using the external Modbus RTU Relay (D) hardware flash
command. Direct onboard relay support requires separate proof that the pulse
continues safely through task stalls, watchdog resets, and power interruption.

## 6. Target software architecture

### 6.1 Components and ownership

```text
app_main
  ├─ Boot/Security/Reset Diagnostics
  ├─ Configuration & Persistent State
  ├─ Network Manager (Wi-Fi; future Ethernet extension)
  ├─ Time Manager
  ├─ Provisioning State Machine
  ├─ MQTT State Machine
  │    ├─ inbound protocol parser/guard
  │    ├─ command dispatcher
  │    └─ response/outbox publisher
  ├─ Command Queue
  ├─ Locker Application Services
  │    ├─ open compartment
  │    ├─ apply config
  │    ├─ state snapshot
  │    └─ heartbeat/door events
  ├─ Modbus Worker (single owner)
  ├─ OTA Manager
  └─ Diagnostics/Telemetry
```

Keep the Pi client's hexagonal intent without reproducing object-heavy
abstractions. Pure domain functions operate on explicit structures. Hardware,
flash, clock, network, MQTT, and Modbus are behind narrow C interfaces that have
host fakes.

### 6.2 Event and task model

Use the default `esp_event` loop for system network/IP events and a dedicated
application event loop or bounded queues for locker lifecycle events. Event
callbacks must copy minimal data and return; they must not parse large JSON,
write flash, or execute Modbus.

Recommended tasks:

| Task | Responsibility | Blocking rule |
| --- | --- | --- |
| Network manager | Wi-Fi station, SoftAP commissioning, and IP state | Owns network state only |
| MQTT manager | Connect/reconnect/subscribe, MQTT event translation | Never performs Modbus or long flash writes in callback |
| Command dispatcher | Parse, validate, deduplicate, persist transaction claim, enqueue execution | Bounded input; backpressure produces a deterministic error where correlation is available |
| Modbus worker | Sole owner of UART/ESP-Modbus master and reconnect state | Processes one priority queue item at a time |
| State poller | Schedules 500 ms snapshots and coalesces overlap | Requests Modbus work; does not access UART directly |
| Persistence/outbox | Serializes durable state updates and response delivery records | Completes durable claim before physical side effect |
| OTA manager | Downloads/verifies inactive image and coordinates quiescence | Cannot update while an open command is in flight |
| Diagnostics | Bounded logs/metrics export and crash reporting | Must drop low-priority telemetry rather than block control |

Prefer static allocation for core queues/tasks after prototype sizing. Every
queue has an explicit capacity, enqueue timeout, overflow counter, and policy.
Avoid a task per command, compartment, or timer.

### 6.3 MQTT state machine

States:

```text
network_down
  → network_ready
  → time_ready
  → provisioning_connecting | device_connecting
  → subscribing
  → online
  → reconnect_wait
```

Requirements:

- MQTT 3.1.1;
- stable client ID;
- clean session disabled;
- QoS 1 command subscription;
- existing Last Will topic/body/QoS/retain semantics;
- unlimited willingness to reconnect;
- bounded exponential backoff with jitter is acceptable only if tests show the
  observable recovery remains within the Pi acceptance gate; fixed five-second
  reconnect is the literal baseline;
- resubscribe only when broker session state requires it;
- flush local pending responses after every successful connection;
- never assume a QoS 1 enqueue means delivery; update local delivery state only
  after the corresponding publish acknowledgement event;
- bound ESP-MQTT's outbox and add application-level response persistence;
- assemble inbound ESP-MQTT data by event offset and total length before parsing;
  fragmented payloads, duplicate fragments, gaps, overlap, and declared lengths
  above the limit must fail deterministically without partial command handling;
- use the certificate bundle or provisioned trust anchor with hostname
  verification; never provide an insecure production switch.

ESP-MQTT moved to the `espressif/mqtt` managed component in ESP-IDF 6. Pin the
component version and test component/IDF upgrades as a compatibility pair.

### 6.4 Command safety and idempotency

The durable order is mandatory:

1. validate envelope and command schema;
2. inspect the message-ID mapping and transaction record;
3. atomically commit the new message-ID mapping and the transaction's
   `in_progress` claim in one journal transaction;
4. enqueue one execution;
5. execute the physical/config side effect;
6. persist final semantic response as pending;
7. publish with a fresh message ID;
8. persist delivery after PUBACK.

Never acknowledge queue admission as command success. Never execute an
`in_progress` command after reboot. Replays of completed transactions publish
the stored response and do not touch hardware.

Use an internal monotonically increasing journal sequence. CRC may detect
accidental corruption but is not a security control. Security-sensitive
credentials, configuration, deduplication, transaction, and outbox records need
authenticated integrity and an anti-rollback design tied to trusted device
state. Power loss at every boundary above must leave either the prior valid
record or one complete new record. Power-cut tests must prove both safety (never
more than one physical actuation) and liveness (a command accepted before the
cut reaches a terminal replayable response or an explicit fail-closed outcome).

### 6.5 Persistent storage

Proposed partition responsibilities:

- encrypted NVS for bootstrap network config, broker settings, client ID,
  credentials, locker UUID, runtime config metadata, and small counters;
- dedicated encrypted journal partition for seen message IDs, command records,
  pending responses, and compaction checkpoints;
- OTA metadata and two application slots;
- encrypted bounded crash/log partition if post-mortem retrieval is required.

NVS is appropriate for small values and includes wear levelling, but rewriting
one growing 10,000-entry blob per command is not. Use append-only records plus
two-phase compaction or an A/B snapshot design. Retain exact IDs initially;
hash-truncation or Bloom filters change collision behavior and require a
separate safety analysis.

The 30-day Pi retention policy cannot be implemented by deleting records against
an untrusted wall clock. Until trusted-time and clock-rollback behavior are
specified and tested, retain deduplication records conservatively by capacity
and monotonic journal order, never expire pending or uncertain transactions, and
fail closed before eviction could permit a duplicate physical side effect.

Persist:

- schema version;
- accidental-corruption check plus authenticated-integrity metadata;
- sequence number;
- last successful wall-clock synchronization and its quality;
- reset reason and firmware version;
- OTA health state.

Credential/config/dedup corruption must block command processing and expose a
service diagnostic. Factory reset must be a deliberate physical or authenticated
commissioning action, never automatic recovery.

### 6.6 Provisioning

Provisioning has two layers:

1. **Local commissioning:** use the device's temporary SoftAP to obtain Wi-Fi
   settings, bootstrap MQTT credentials, broker URL/trust policy, and one-time
   provisioning token.
2. **Existing backend MQTT provisioning:** execute the current register/reply
   exchange unchanged.

Pilot devices may also use a fixture/USB command with secret-safe output.
Production V1 commissioning uses ESP-IDF network provisioning over SoftAP with
`protocomm_security2` (SRP6a + AES-GCM), a unique per-device verifier, and a
limited commissioning window. Security 0 is prohibited. The SoftAP must stop
after successful commissioning and may be reopened only by a deliberate
physical or authenticated recovery action.

Do not compile fleet-wide bootstrap secrets or provisioning tokens into
firmware. Manufacturing must inject per-device commissioning material or use a
documented secure handoff. Clear the one-time token after success while keeping
the issued device credentials. Re-provisioning must require an authenticated
local action and backend reset.

A lost provisioning reply after the backend consumes the token must have a
tested recovery runbook and deterministic device state. Switching back to a Pi
client also requires a backend provisioning reset and newly issued credentials;
old ESP or Pi credentials and persistent sessions must never be reused.

### 6.7 Time basis

Use monotonic FreeRTOS/ESP timers for:

- reconnect cooldowns;
- Modbus frame spacing;
- command durations;
- door-detection windows;
- uptime.

Use UTC wall clock only for MQTT timestamps, certificate validation, and
operator records. Synchronize with SNTP and expose time quality. Smooth updates
are useful after initial synchronization; a cold boot may require an immediate
step.

Cold-boot TLS and trustworthy time form an explicit design spike:

- unauthenticated NTP before TLS is simple but can be manipulated on the local
  network;
- certificate validation normally needs plausible wall time;
- a battery-backed RTC, authenticated/pinned time bootstrap, or an approved
  trust-anchor policy can close the loop.

No production board is selected until this is resolved and power-cycle tested.
The client must not emit fabricated ISO-8601 timestamps as though they were
trusted.

### 6.8 Modbus worker

One task owns the UART and transceiver. All operations enter one bounded priority
queue. Implement:

- RS-485 half-duplex UART mode with controlled RTS/DE;
- FC01 relay reads;
- FC02 contiguous digital-input reads;
- raw FC05 support for Waveshare flash addresses and all-off;
- configurable framing and timeout within current bounds;
- 3.5-character / fixed 1.75 ms inter-frame gap plus measured margin;
- five-attempt reconnect cycles and 60-second default cooldown;
- explicit `disconnected`, `connecting`, `connected`, and `unreachable` states;
- one operation retry only after a successful reconnect;
- board-level unknown substitution for failed sensor reads.

ESP-Modbus v2 is the preferred maintained component, but its API must be spiked
against Waveshare's nonstandard FC05 value semantics. If the high-level
descriptor API cannot issue the exact four data bytes used today, implement the
small RTU master operation through supported lower-level APIs or a focused
adapter. Golden frame captures must prove byte equivalence.

Modbus TCP is deferred. Add it only after a separate need, ADR, and parity matrix
because it changes failure modes, network ownership, and test scope.

### 6.9 OTA and recovery

Use signed HTTPS OTA with:

- two application slots;
- rollback enabled;
- a signed manifest that binds the image digest, version, hardware compatibility,
  minimum permitted version, rollout cohort, and expiry or freshness data;
- manifest and image signature verification with replay protection;
- server certificate/hostname verification;
- version and hardware-compatibility checks;
- staged rollout cohorts with an operator freeze/abort control;
- no update while a command is in flight;
- post-boot pending-verification state;
- local boot self-tests that decide whether the bootloader image can be marked
  valid, separated from externally observed service readiness;
- external readiness checks for network, trusted time, broker authentication,
  subscription, response outbox, and Modbus availability;
- automatic rollback if health is not confirmed;
- operator-triggered rollback to an explicitly approved release;
- signing-key rotation with overlap, revocation, and recovery procedures;
- anti-rollback enabled only after key custody, emergency recovery, and version
  policy are operationally proven.

Secure Boot v2 and Flash Encryption should be enabled together in production.
Prototype and manufacturing flows must be rehearsed before irreversible eFuse
settings. Signing keys stay outside the repository and build runners receive
only the minimum signing capability.

The OTA control plane, manifest contract, signature/key lifecycle, cohort and
freeze semantics, replay prevention, readiness model, and operator rollback are
architecture-significant and require a separate accepted OTA ADR before OTA
implementation. That ADR is a mandatory upstream deliverable of Phase 0; this
plan does not create or pre-accept it.

### 6.10 Watchdogs and recovery policy

- enable interrupt and task watchdogs;
- subscribe control tasks individually to the task watchdog;
- set timeout above measured worst-case legitimate operations;
- never feed a watchdog from a timer/ISR that can continue while the task is
  dead;
- keep the brownout detector enabled and record reset reason;
- treat repeated crash/brownout/rollback loops as safe degraded mode, with relay
  commands disabled until state is healthy;
- startup all-off remains best effort under the existing reachable/unreachable
  distinction.

### 6.11 Logs, metrics, and traces

Local structured logs must include severity, monotonic time, synchronized UTC
when available, firmware version, reset reason, locker UUID after provisioning,
transaction ID, message ID, action, Modbus slave/address, and stable error code.
Never log credentials, one-time tokens, complete payloads, or registration
topics.

Provide:

- UART logs for development/service;
- a bounded in-memory ring for recent logs;
- an encrypted bounded flash crash/log record only for high-severity events;
- counters/gauges for reconnects, command outcomes, queue high-water marks,
  Modbus timeout/CRC errors, unknown inputs, NVS/journal failures, watchdog
  resets, brownouts, OTA attempts/rollbacks, free heap, minimum free heap, and
  task stack high-water marks.

Preserve optional W3C `traceparent` parsing and forwarding. A minimal OTLP/HTTP
exporter may provide parity with Pi observability if resource profiling and
outage tests pass. Telemetry must be opt-in, bounded, and unable to block control
or consume the command-response outbox. Adding a diagnostic MQTT topic is not
part of this plan because it would change the backend contract.

## 7. Feature parity matrix

`Unknown` marks behavior that is not fully established by current code,
documentation, or hardware evidence.

| Feature | Pi behavior | ESP approach | Risk / unknown | Verification | Acceptance |
| --- | --- | --- | --- | --- | --- |
| Client identity | Stable file-backed ID | Stable encrypted NVS ID | Factory reset abandons broker session | Reboot/power-cut test | Same ID across 100 resets unless explicit reset |
| One-time provisioning | MQTT register/reply, 30 s timeout | Same MQTT exchange after secure local commissioning | Exact retry UX after consumed/lost token | Golden vectors + real backend | Same success/error payloads and no leaked token |
| Opaque credentials | Username authenticates; UUID names topics | Store separately in encrypted NVS | Legacy migration shape | Contract and migration test | Opaque username never used as topic UUID |
| TLS | OS CA store, hostname verification | ESP certificate bundle or pinned CA, hostname verification | Cold-boot trusted time | MITM/expired/wrong-host tests | All invalid chains/hosts rejected; valid broker connects |
| MQTT version | Backend-compatible 3.1.1 behavior | MQTT 3.1.1 | ESP-MQTT component changes | Broker packet capture | CONNECT/session flags accepted by Mosquitto |
| Persistent session | `clean=false` | Disable clean session | Session expiry broker policy not explicit | Broker restart/offline command test | Queued QoS 1 command arrives once logically |
| MQTT reconnect | Fixed 5 s, unlimited | State machine, unlimited, bounded delay | Literal timing vs jitter | Link/broker fault tests | Recovers without reboot within defined gate |
| QoS/retain | QoS 1; only snapshots retained | Exact topic policy table | PUBACK event semantics | Packet capture + broker | All publications match policy |
| LWT/shutdown | Non-retained offline will; explicit shutdown | Same LWT; explicit offline before reboot/update | Hard reset cannot publish | Broker observation | Correct reason for graceful and ungraceful paths |
| Envelope | UUID message ID + ISO timestamp | ESP RNG UUID + synchronized UTC | Time unavailable at cold boot | Schema/time tests | Every outbound payload validates |
| Trace context | Optional top-level `traceparent` | Parse/forward optional value | Full OTLP parity may be expensive | Golden vectors + footprint | Context preserved without affecting validation |
| Command schemas | Zod validation | Generated/manual bounded parser from shared vectors | JSON library differences, unknown fields | Shared valid/invalid corpus | Same accept/reject outcomes |
| Message dedup | 30 d / 10k IDs | Durable append journal, same policy | Flash wear/capacity | Power-cut + longevity | No duplicate side effect in fault campaign |
| Transaction dedup | Durable in-progress/completed response | Durable journal state machine | Atomicity around relay write | Reset at every transition | At most one pulse per transaction |
| Response recovery | Pending until PUBACK, replay with new message ID | Persistent outbox + PUBACK completion | ESP-MQTT outbox interaction | Broker loss during response | Response eventually arrives; pulse not repeated |
| Interrupted command | Final `UNKNOWN_ERROR`, never rerun | Same | Distinguishing reset location impossible | Forced resets around write | No physical re-execution |
| `open_compartment` | Hardware flash then immediate success response | Same FC05 bytes and ordering | ESP-Modbus raw request support | Frame capture + HIL | One 100–500 ms board-timed pulse |
| Door detection | 500 ms reads, timeout = heartbeat | Same scheduled work through bus queue | Queue load can stretch interval | HIL timing | Outcomes match within one poll interval |
| Uncommanded open | Detect closed→open without recent explanation | Same state machine | Relay-fire history across reboot is currently memory-only on Pi | Reboot scenario review | Match agreed Pi behavior; document any persistence choice |
| Runtime config | Separate overlay, SHA-256, rollback | Versioned encrypted NVS config with A/B commit | `updatedAt` requires trusted time | Golden vectors + power cuts | Old or new complete config, never mixed |
| Pre-config behavior | Reject opens; no snapshot | Same | None | Component test | `RUNTIME_CONFIG_NOT_APPLIED`, no relay traffic |
| Heartbeat | Configurable interval, uptime, bus boolean | FreeRTOS timer + monotonic uptime | Timer behavior under network outage | Soak + broker capture | Interval within ±5%; no timer accumulation |
| Snapshot polling | 500 ms; batched FC02 per board | Same through single Modbus worker | Large bus may exceed 500 ms | Simulator/HIL load | No overlap; force coalescing; correct sorted snapshot |
| Unknown debounce | Publish unknown on third consecutive failed cycle | Same per target | Cycle duration varies on timeout | Fault injection | Exactly same state-vector transitions |
| Modbus RTU | One serialized bus, priority queue | One UART-owning worker | Priority semantics need explicit tie-breaking | Deterministic queue test | No concurrent frames; commands precede queued polls |
| Modbus TCP | Not implemented | Not in parity release | Documentation previously implies broader support | Source audit | Explicitly absent |
| Inter-frame delay | Formula + 1 ms margin | Hardware timer formula + measured margin | Transceiver turnaround latency | Logic analyzer | No frame starts before required silence |
| Reconnect | Five attempts/cycle, cooldown, unreachable | Same state machine | Real error codes remain unverified on Pi | Cable pull/short/silent slave | Recovers without reboot; bounded retry rate |
| Startup failsafe | All-off per configured board, nuanced failure | Same exact FC05 operation | No mapping before first config means no boards | HIL matrix | Matches reachable/partial/unreachable outcomes |
| Persistence corruption/rollback | Fail closed | Authenticated integrity and device-rooted anti-rollback | Field recovery and key/state design | Bit flips, truncation, valid-old replay | Commands disabled; no factory reset |
| Graceful lifecycle | Drain commands, bounded teardown | Quiesce command intake for OTA/reboot | ESP reset has shorter operator expectations | Target tests | No reset while physical command active |
| Updates | Watchtower replaces container | Signed A/B HTTPS OTA | Entirely different operational mechanism | OTA interruption matrix | Bad image rolls back; good image canary succeeds |
| Logs | Console + optional OTLP | UART/ring + optional bounded OTLP | Dashboard feature parity | Collector outage/volume tests | Control unaffected; no secret leakage |
| Crash recovery | Docker restart | Watchdog/panic reboot + crash record | Reboot-loop containment | Fault injection | Returns to known degraded/online state |

## 8. Incremental implementation plan

### Agent execution and worktree strategy

The delivery process is tool-neutral and may use human developers or coding
agents in Cursor, Claude Code, or another environment. Agent products are
optional execution surfaces, not runtime, firmware, architecture, or QA
dependencies.

Use this lean role model:

- **Migration Lead / Planner:** freezes scope, slice boundaries, dependency
  order, acceptance packets, and merge order;
- **Contract / Fixture Owner:** exactly one owner changes AsyncAPI, schemas,
  bounds, golden fixtures, and the traceability manifest;
- **Feature Implementer:** one owner per conflict-free vertical slice, working
  in an isolated Git worktree;
- **Independent Verifier / Reviewer:** did not implement the slice and reruns
  tests, reviews evidence and safety claims, and challenges missing cases;
- **Human HIL Operator:** controls physical equipment, records fixture/firmware
  identity, observes unsafe states, and can stop the rig.

Contract and golden-fixture changes are strictly sequential: the Contract /
Fixture Owner lands one reviewed baseline before implementers consume it.
Independent vertical slices may then proceed in parallel only when their file
ownership, contract version, hardware resources, and integration order do not
conflict.

Before work starts, the Migration Lead gives each slice a small immutable
context/acceptance packet containing requirement and parity IDs, source ADRs,
the exact contract/fixture commit, payload and hardware bounds, non-goals,
required red tests, applicable test layers, and acceptance evidence. Scope
changes produce a reviewed packet revision rather than an informal prompt
change. Each slice follows red-green-refactor, publishes evidence tied to the
source commit and exact firmware image hash, and ends in a small reviewable PR.
No feature merges merely because host tests or an agent's summary says it works.

Equivalent optional execution examples:

- Cursor can run a slice in an isolated worktree through its documented
  worktree support and can delegate bounded research or verification to
  subagents. The role, packet, TDD cycle, evidence, and independent review remain
  unchanged.
- Claude Code can run a session in a Git worktree or configure a subagent with
  `isolation: worktree`. The same role boundaries and merge order apply; a
  subagent is not automatically an independent reviewer if it inherits the
  implementer's assumptions or evidence.
- Plain `git worktree` plus either tool is equally valid. Parallelism is never
  used for the shared contract, fixtures, journal format, or other overlapping
  safety-critical ownership.

### Phase 0 — Freeze evidence and gates

Deliver:

- export shared MQTT examples into a language-neutral golden-vector test bundle;
- record Pi packet captures for provisioning, commands, responses, events,
  heartbeat, snapshot, LWT, reconnect, and duplicate delivery;
- record Modbus request/response frames for FC01, FC02, flash FC05, and all-off;
- write a traceability manifest mapping every parity row to test IDs;
- decide measurable pilot SLOs, minimum pilot device-hours, sample size,
  confidence/reporting method, and data-retention requirements;
- specify binding maximum MQTT topic, payload, string, array, and compartment
  counts and their rejection behavior, including fragmented ESP-MQTT delivery;
- specify one atomic journal transaction for message-ID mapping plus transaction
  claim, authenticated persistence, anti-rollback, conservative dedup retention,
  and power-cut safety/liveness gates;
- define a serialization barrier between `apply_config` and open commands:
  accepted opens execute entirely against the old or new complete mapping, never
  an in-flight mixture;
- decide whether relay-explanation state survives reboot and bind the exact
  `compartment_uncommanded_open` semantics;
- specify graceful shutdown parity, bounded drain behavior, and behavior when
  the provisioning reply is lost after token consumption;
- produce and accept the separate OTA ADR described in section 6.9 before OTA
  implementation;
- treat this plan and every `Proposed` ADR as design input only; only accepted
  ADRs and approved contracts are binding architecture.

Exit:

- every canonical topic/payload and Modbus operation has a reviewed vector;
- unresolved Pi behavior is explicitly listed rather than guessed;
- every item above has an owner, reviewed decision, executable gate, and
  traceability ID;
- backend and Pi suites are green at the frozen baseline.

Rollback: documentation and fixtures only; no deployed effect.

### Phase 1 — Platform and hardware spikes

Implement throwaway vertical spikes on at least two candidates:

- Wi-Fi station acquisition, reconnect, and credential recovery;
- authenticated SoftAP commissioning and shutdown after successful setup;
- trusted-time bootstrap;
- verified MQTTS connection and QoS 1 round trip;
- exact raw Waveshare FC05 and FC02 through isolated RS-485;
- encrypted NVS/journal power-cut behavior;
- dual-slot signed OTA rollback;
- task watchdog and reset-reason capture.

Measure:

- image and partition size;
- internal SRAM static use;
- peak/minimum free heap during TLS, MQTT, OTA, and Modbus overlap;
- each task's stack high-water mark;
- network and broker recovery time;
- Modbus turnaround and inter-frame timing;
- idle, typical, and peak current, rail droop, and brownout threshold;
- complete prototype BOM, quoted unit price at pilot and expected production
  quantities, supplier count, stock, and lead time;
- comparable Pi and ESP commissioning, update, maintenance, failure-recovery,
  and energy effort using a predefined operator runbook.

Exit:

- at least two viable candidates have comparable data;
- no candidate exceeds 70% of internal SRAM under worst measured load or 70% of
  allocated task stacks in soak;
- exact Waveshare frame behavior is proven;
- time bootstrap has a reviewed production path;
- hardware shortlist can be narrowed by evidence;
- each candidate has a dated BOM/TCO and supply snapshot comparable to the Pi
  baseline, with no unsupported cost or availability claim.

Rollback: discard spikes; no contract or backend change.

### Phase 2 — Host-tested protocol core

Create the future ESP client as a new top-level component only when
implementation begins (proposed name: `locker-client-esp32/`). Build pure,
host-testable modules for:

- JSON envelope and schema validation;
- message/transaction IDs;
- config normalization and SHA-256;
- command state machine;
- snapshot/debounce and door-event state machines;
- error-code mapping;
- persistence record codecs and migration.

Consume the same examples/schemas; do not copy and edit contract fixtures.

Exit:

- all valid golden vectors are accepted;
- generated invalid/mutated vectors are rejected with expected error codes;
- host sanitizer/static-analysis builds pass;
- deterministic unit coverage exists for every parity row not requiring target
  hardware.

Rollback: independent component; Pi client remains production path.

### Phase 3 — Vertical slice: provision and heartbeat

On one target:

1. secure local commissioning;
2. unchanged MQTT provisioning exchange;
3. encrypted credential persistence;
4. device reconnect with stable persistent session;
5. heartbeat with synchronized timestamp and Modbus health;
6. graceful and ungraceful offline signals.

Exit:

- real backend provisions a device without code/config changes;
- 100 reboot and 20 network/broker restart cycles retain identity;
- lost provisioning responses recover only through the approved backend reset
  and new-provisioning flow;
- no credential/token appears in logs, crash dump, or unencrypted flash;
- heartbeat/reconnect/LWT and graceful shutdown parity behavior pass.

Rollback/coexistence: use a dedicated test locker bank. Never run Pi and ESP
clients simultaneously with the same client ID/locker identity.

### Phase 4 — Vertical slice: one safe open

Add:

- atomic message-ID mapping/transaction claim and durable response outbox;
- one serialized Modbus worker;
- exact hardware flash command;
- immediate command acknowledgement;
- duplicate and reboot recovery;
- one-compartment door detection.

Exit:

- 1,000 commanded opens on HIL produce exactly 1,000 pulses;
- duplicate delivery produces no additional pulse;
- reset at every persistence/write/response boundary produces at most one pulse;
- every power-cut point also reaches the specified replayable terminal or
  fail-closed liveness outcome;
- all responses validate and eventually arrive after recoverable outages;
- pulse width remains within configured Waveshare step tolerance.

Rollback: disable ESP bank and restore the Pi bank using separate persisted
identity. Do not share dedup state between implementations.

### Phase 5 — Vertical slice: runtime config and full state

Add:

- `apply_config` with durable rollback and the approved open-command barrier;
- multi-board mapping;
- startup all-off;
- 500 ms batched polling;
- unknown debounce;
- change-only retained snapshots;
- all door outcome/uncommanded-open events;
- reconnect cycles and unreachable state.

Exit:

- all parity scenarios pass against simulator and three-board HIL;
- 24-hour bus soak has zero overlapping frames and zero unexplained missed
  transitions;
- forced config power cuts always boot old or new complete config;
- concurrent config/open tests always use one complete mapping version;
- unhealthy boards do not suppress healthy-board snapshots.

### Phase 6 — Production security and OTA

Add/rehearse:

- production key custody and remote signing;
- Secure Boot v2 + Flash Encryption;
- encrypted NVS/journal/crash partitions;
- debug-port production policy;
- signed HTTPS OTA, rollback, and optional anti-rollback;
- manufacturing fixture and per-device identity injection;
- software bill of materials and dependency pinning.

Exit:

- unsigned/tampered firmware never boots or installs;
- interrupted OTA at every tested point retains one bootable image;
- failed health confirmation rolls back automatically;
- 100-device simulated staged rollout and two hardware canaries complete;
- documented recovery works on production-fused sample units.

### Phase 7 — Pilot and controlled production

Rollout:

1. bench HIL;
2. internal cabinet;
3. one non-critical site;
4. 5% canary;
5. 25%;
6. broad opt-in.

Keep Pi images/configuration and hardware available for rollback. A locker bank
has one active client implementation at a time. Use separate MQTT client IDs and
re-provision when switching controller generation so stale persistent sessions
cannot compete.

Pilot exit:

- 30-day canary with no duplicate physical actuation;
- the predeclared minimum aggregate device-hours and per-device observation
  window are reached, with confidence intervals or event upper bounds reported
  for zero/rare safety and reliability failures;
- ≥99.9% scheduled heartbeats observed while site network is available;
- P95 command-to-pulse ≤1 second on a healthy idle bus;
- P99 network/broker recovery ≤2 minutes under the approved policy;
- zero unrecovered OTA failures;
- zero unexplained watchdog/brownout reset loops;
- no critical/high security findings;
- operator rollback drill completed within 30 minutes;
- ESP and Pi have comparable 30-day evidence for controller BOM/TCO,
  acquisition price and variance, supplier availability and lead time, idle and
  typical energy use, commissioning/update/maintenance effort, observed failure
  rate, automatic recovery rate, and P50/P95 recovery time;
- the go/no-go record states the thresholds chosen before the pilot and whether
  each platform met them. A lower ESP purchase price alone is not sufficient to
  select it if TCO, reliability, recovery, or availability gates fail.

## 9. QA and verification strategy

### 9.1 Traceability

Maintain `feature → requirement → test → evidence → release gate` in a
machine-readable manifest. Every parity-matrix row receives IDs such as:

- `MQTT-CONTRACT-*`;
- `CMD-IDEMP-*`;
- `MODBUS-SAFETY-*`;
- `PERSIST-POWER-*`;
- `OTA-ROLLBACK-*`;
- `SEC-*`;
- `HIL-*`.

A release is blocked if a required feature has no test/evidence link or if an
unknown behavior has been silently converted into an assumption.

No feature or parity row is considered migrated until its requirement IDs map
to automated tests at the applicable layers and to a reviewed acceptance
artifact, such as a golden-vector report, broker capture, target log, HIL
measurement, or fault-campaign result.

### 9.2 Test-driven development and coverage gates

Every feature follows a binding red-green-refactor cycle:

1. add or change an automated test that fails for the intended behavior;
2. implement only enough production code to make the test pass;
3. refactor while keeping the relevant suite green.

The linked acceptance evidence is part of completion, not a later QA activity.
Critical paths require the applicable combination of unit,
contract/golden-vector, integration, target, HIL, and fault-injection tests.
Host-only coverage cannot establish UART timing, flash power-loss safety,
watchdog behavior, relay pulse safety, or physical recovery.

Coverage reporting must:

- publish line and branch coverage separately for host-testable production
  modules;
- define per-module thresholds before implementation, with higher branch gates
  for command idempotency, persistence codecs/state transitions, parser and
  schema validation, configuration rollback, and Modbus scheduling;
- list justified exclusions for generated code, vendor components, defensive
  hardware-only branches, and unreachable assertions;
- fail the gate on threshold regression or on any uncovered requirement,
  regardless of the aggregate percentage;
- pair percentage metrics with requirement/feature traceability and the
  layer-specific acceptance evidence above.

A single repository-wide “100% coverage” target is prohibited because line
coverage does not prove branch behavior or target/HIL safety. If a percentage is
reported, it must name its scope, metric (line or branch), exclusions, and known
limitations.

Gate: every migrated feature has a recorded failing-test change, passing
automated tests at all applicable layers, and linked acceptance evidence; line
and branch thresholds are met without reducing an existing gate.

### 9.3 Contract and golden-vector tests

- validate AsyncAPI syntax and all referenced JSON Schemas;
- run every canonical example against backend, Pi, and ESP parsers;
- create producer tests that validate real ESP output;
- mutate required IDs, types, bounds, UUIDs, hashes, timestamps, action names,
  unknown fields, and nesting;
- compare topics, QoS, retain flags, LWT, ordering, and replay semantics;
- capture Mosquitto traffic for protocol version, clean-session flag,
  subscription QoS, PUBACK, and reconnect/session-present behavior;
- run backend component tests for every contract-relevant change even when
  backend source is unchanged.

Gate: zero contract diffs outside normalized generated fields.

### 9.4 Host unit, component, and static-analysis tests

Build platform-independent modules with fake clock, RNG, persistence, MQTT,
network, and Modbus interfaces. Run:

- Unity/CMock or an equivalent lightweight C test setup;
- ESP-IDF default compiler warnings treated as errors, with any targeted
  suppression reviewed and documented as a confirmed false positive;
- the ESP-IDF GCC Static Analyzer enabled through
  `CONFIG_COMPILER_STATIC_ANALYZER`;
- IDF Clang-Tidy through `idf.py clang-check` as an additional CI job, while
  explicitly accounting for Espressif's warning that the functionality and its
  Clang toolchain are still under development and may introduce breaking
  changes;
- optional Cppcheck as an independent second analyzer, pinned and configured
  separately from the ESP-IDF tools;
- AddressSanitizer and UndefinedBehaviorSanitizer on host;
- fuzzing for JSON/parser and journal-record inputs;
- deterministic state-machine/model tests.

Static-analysis findings are triaged to zero unexplained high-confidence
findings. Suppressions must be narrow and justified. Static analysis supplements
but never replaces target, HIL, or fault-injection testing.

Gate: all tests and required analyzers pass; no sanitizer issue; parser fuzz
campaign completes at least 10 million inputs or 24 hours without crash/hang.

### 9.5 Target tests

Use ESP-IDF Unity tests and pytest-embedded for:

- NVS/flash driver behavior;
- task/queue timing;
- watchdog configuration;
- reset reason;
- network/MQTT integration;
- OTA boot state;
- real UART RS-485 timing.

Record heap minimum and stack high-water marks. Gate: ≥30% measured internal SRAM
headroom and ≥30% task-stack headroom in the worst target scenario.

### 9.6 Modbus simulator and HIL rig

Software simulator:

- multiple slave IDs;
- configurable latency;
- CRC error, timeout, exception, partial response, wrong slave, and stale frame;
- relay flash-register emulation;
- digital-input transitions and jam mode.

Physical HIL:

- target board;
- isolated RS-485;
- at least three production Waveshare boards;
- logic analyzer on TX/RX/DE;
- relay/contact and digital-input sensing;
- controllable board power;
- network switch/AP control;
- programmable cabinet supply/electronic load;
- remote target power/reset and UART capture.

Gate:

- no simultaneous drivers or early DE release;
- no RTU frame starts before required silence;
- zero unexplained timeout in 100,000 healthy-bus transactions;
- exact pulse count and requested hardware-timed duration.

### 9.7 Fault injection

Inject at deterministic state-machine boundaries:

- Wi-Fi loss, access-point restart/change, wrong credentials, weak signal, and
  DHCP expiry;
- broker stop/restart and TLS proxy restart;
- duplicate and out-of-order commands;
- old persistent-session commands;
- response loss before/after PUBACK;
- RS-485 open, short, noisy line, missing termination, silent slave, wrong ID;
- target reset before/after claim, Modbus write, final persistence, publish, and
  delivery marker;
- brownout during flash erase/write and OTA;
- NVS full, corrupt, truncated, bit-flipped, and version-unsupported records;
- queue saturation and slow telemetry collector;
- invalid clock, NTP loss, and large clock correction;
- watchdog stalls in each critical task.

Safety gate: no scenario produces more than one relay pulse per transaction.
Recovery gate: every recoverable fault returns automatically or enters an
explicit fail-closed state with actionable diagnostics.

### 9.8 Soak, stress, and longevity

- 72-hour pre-pilot HIL soak with production polling and heartbeat rates;
- 30-day canary;
- accelerated command, reconnect, and config cycles;
- flash-write amplification and projected erase-life calculation using measured
  writes, not nominal message counts;
- repeated TLS handshakes and OTA downloads while polling;
- memory leak trend from minimum-free-heap and task stacks.

Gates:

- no downward heap trend over 72 hours after warm-up;
- no watchdog reset, deadlock, queue overflow, or lost pending response;
- zero duplicate actuations;
- ≥99.99% valid Modbus transactions on healthy HIL;
- flash-life projection ≥10 years at twice expected production command/config
  volume with at least 2× uncertainty margin.

### 9.9 OTA and rollback tests

Test:

- valid upgrade and downgrade policy;
- manifest tampering, expiry/freshness, replay, cohort mismatch, and rollout
  freeze/abort;
- invalid signature;
- wrong hardware target;
- truncated image;
- power/network loss throughout download/write/boot verification;
- crash, no network, no broker, unreadable NVS, and unavailable Modbus during
  pending verification;
- signing-key rotation and recovery;
- operator rollback and separation of local boot validity from external
  readiness;
- anti-rollback only after ordinary rollback is proven.

Gate: one bootable known-good slot remains in every tested interruption.

### 9.10 Security tests

- threat model manufacturing, local service, network, broker, OTA, and physical
  flash access;
- verify Secure Boot/Flash Encryption/eFuse state on sampled production units;
- search binaries, flash images, logs, crash dumps, and telemetry for secrets;
- TLS wrong-host, untrusted CA, expired/not-yet-valid, downgrade, and MITM tests;
- provisioning brute force/replay and commissioning-window tests;
- malformed MQTT and Modbus fuzzing;
- dependency/SBOM and known-vulnerability scanning;
- debug-port lock-down and authorized recovery test;
- backend ACL cross-locker and revoked-session tests.

Gate: zero unresolved critical/high findings and documented disposition for
medium findings.

### 9.11 Power, EMC, and environmental tests

Before production:

- cold/hot input range and rapid cycling;
- relay/lock switching transients;
- brownout threshold and recovery;
- EFT/burst, surge, ESD, conducted/radiated emissions and immunity appropriate
  to the deployment/product classification;
- RS-485 common-mode and cable-length testing;
- thermal soak in enclosure;
- Wi-Fi antenna performance and interference in the intended metal cabinet.

Acceptance values must be set with the electrical engineer and applicable
standards laboratory. Passing firmware tests is not evidence of EMC or product
safety compliance.

## 10. Coexistence and rollback

- Pi and ESP clients remain independently buildable and releasable.
- The backend and AsyncAPI stay implementation-neutral.
- A locker bank must not have both clients online simultaneously.
- Switching implementations uses a controlled maintenance window:
  1. block new open requests;
  2. wait for terminal command state;
  3. stop old client cleanly;
  4. reset provisioning in the backend and revoke the old identity;
  5. provision the replacement with new credentials and validate it;
  6. force config and snapshot;
  7. reopen traffic.
- Keep a tested Pi rollback kit for every pilot site.
- Retained physical state can remain bank-scoped, but persistent command session
  state is implementation-local and must not be copied or guessed.
- Pi rollback never reuses previous Pi or ESP credentials, client sessions, or
  deduplication state.

## 11. Main effort drivers

1. Power-loss-safe idempotency around a physical side effect.
2. Trusted cold-boot time before MQTTS certificate validation and timestamps.
3. Flash wear and capacity for 30-day/10,000-ID dedup parity.
4. Exact nonstandard Waveshare FC05 behavior through ESP-Modbus v2.
5. Production electrical design, EMC, isolation, and power integrity.
6. Secure manufacturing, key custody, and irreversible eFuse workflow.
7. HIL automation and long-running fault campaigns.
8. Optional OTLP observability within bounded MCU resources.
9. Parallel maintenance while the Pi client's contract continues evolving.
10. Evidence-based comparison of Pi and ESP stability, recovery, acquisition
    cost, availability, energy, and operating effort.

## 12. Open decisions

- Which shortlisted SoC/module/board wins measured Phase 1 evaluation?
- What evidence and product milestone should trigger the later Ethernet variant,
  and should it replace or supplement Wi-Fi?
- Is a battery-backed RTC required, or can authenticated time bootstrap meet
  cold-boot TLS requirements?
- What exact flash size and partition table meet OTA, journal, and crash needs?
- Which authenticated journal construction and device-rooted anti-rollback
  mechanism protect command state beyond accidental-error CRC detection?
- What exact SoftAP setup UX, physical activation method, commissioning-window
  duration, and per-device verifier delivery process will V1 use?
- Is optional OTLP export required for first production parity or deferred after
  trace-context/log parity?
- What is the approved stale-command policy after long MQTT sessions? Any change
  affects both clients and requires a separate contract decision.
- Should relay-fire explanation state survive reboot? The Pi currently keeps it
  in memory, so persistence would intentionally exceed current behavior.
- Which product/environmental standards and quantitative EMC/power limits apply?
- What fleet size and event rate should dimension broker sessions, journal life,
  OTA rollout, and observability?
- What minimum pilot device-hours, sample size, confidence method, and event
  upper bounds make the stability comparison decision-worthy?
- What predeclared BOM/TCO, price variance, supplier/lead-time, energy,
  maintenance-effort, failure-rate, automatic-recovery, and recovery-time gates
  would justify selecting ESP over the parallel Pi alternative?

## 13. Sources

All web sources were accessed on 2026-08-25 or 2026-08-26.

### Repository sources

- [Canonical MQTT contract](../asyncapi/mqtt.yaml)
- [ADR-0002: message and transaction IDs](../adr/0002-mqtt-message-id-and-transaction-id-separation.md)
- [ADR-0004: Waveshare hardware flash](../adr/0004-waveshare-hardware-flash-and-supported-boards.md)
- [ADR-0014: MQTT session and reconnect](../adr/0014-locker-client-mqtt-session-and-reconnect.md)
- [ADR-0015: AsyncAPI contract](../adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md)
- [ADR-0016: retained compartment snapshots](../adr/0016-retained-compartment-snapshot-and-door-state-persistence.md)
- [ADR-0024: Pi client hexagonal rewrite](../adr/0024-locker-client-v2-hexagonal-rewrite.md)
- [ADR-0035: Modbus RTU timing](../adr/0035-enforce-modbus-rtu-inter-frame-delay.md)
- [ADR-0038: polling and snapshots](../adr/0038-batched-door-polling-and-change-only-snapshots.md)
- [ADR-0041: response recovery](../adr/0041-locker-client-command-response-recovery.md)
- [ADR-0046: persistence hardening](../adr/0046-locker-client-local-persistence-hardening.md)
- [ADR-0048: one-time provisioning](../adr/0048-one-time-hmac-locker-provisioning.md)
- [ADR-0050: per-provisioning identities](../adr/0050-per-provisioning-mqtt-credential-identities.md)
- [ADR-0051: Modbus reconnect](../adr/0051-modbus-reconnect-declares-an-unreachable-bus.md)
- [ADR-0053: public MQTTS](../adr/0053-terminate-public-mqtt-tls-at-traefik.md)
- `locker-client/src/`, `locker-client/tests/`, `locker-client/README.md`,
  `locker-client/CUTOVER.md`
- `locker-backend/app/Mqtt/`, `locker-backend/app/Services/MqttAclService.php`

### Official and primary external sources

- Cursor, [Worktrees](https://cursor.com/docs/configuration/worktrees)
- Cursor, [Subagents](https://cursor.com/docs/subagents)
- Cursor, [CLI worktrees](https://cursor.com/docs/cli/using#cli-worktrees)
- Anthropic, [Claude Code worktrees](https://docs.anthropic.com/en/docs/claude-code/worktrees)
- Anthropic, [Claude Code subagents](https://docs.anthropic.com/en/docs/claude-code/sub-agents)
- Espressif, [ESP-IDF versions](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/versions.html)
- Espressif, [ESP-MQTT](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/protocols/mqtt.html)
- Espressif, [ESP-Modbus](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/protocols/modbus.html)
- Espressif, [UART and RS-485 modes](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/peripherals/uart.html)
- Espressif, [Event Loop Library](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/system/esp_event.html)
- Espressif, [IDF FreeRTOS](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/system/freertos_idf.html)
- Espressif, [NVS](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/storage/nvs_flash.html)
- Espressif, [NVS Encryption](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/storage/nvs_encryption.html)
- Espressif, [Watchdogs](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/system/wdts.html)
- Espressif, [Fatal errors and brownout](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-guides/fatal-errors.html)
- Espressif, [Core dumps](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-guides/core_dump.html)
- Espressif, [OTA and rollback](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/system/ota.html)
- Espressif, [HTTPS OTA](https://docs.espressif.com/projects/esp-idf/en/stable/esp32h2/api-reference/system/esp_https_ota.html)
- Espressif, [Secure Boot v2](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/security/secure-boot-v2.html)
- Espressif, [Flash Encryption](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/security/flash-encryption.html)
- Espressif, [Unified provisioning](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/provisioning/provisioning.html)
- Espressif, [ESP-IDF 6 provisioning migration](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/migration-guides/release-6.x/6.0/provisioning.html)
- Espressif, [System time and SNTP](https://docs.espressif.com/projects/esp-idf/en/latest/esp32/api-reference/system/system_time.html)
- Espressif, [Unit testing](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-guides/unit-tests.html)
- Espressif, [ESP-IDF tests with pytest](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/contribute/esp-idf-tests-with-pytest.html)
- Espressif, [ESP-IDF GCC Static Analyzer](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-guides/code-quality/static-analyzer.html)
- Espressif, [IDF Clang-Tidy](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-guides/tools/idf-clang-tidy.html)
- Espressif, [ESP-IDF 6.0 build-system warning policy](https://docs.espressif.com/projects/esp-idf/en/stable/esp32/migration-guides/release-6.x/6.0/build-system.html)
- Espressif, [ESP32-S3 datasheet](https://documentation.espressif.com/esp32-s3_datasheet_en.pdf)
- Espressif, [ESP32-C6 datasheet](https://documentation.espressif.com/esp32-c6_datasheet_en.pdf)
- Espressif, [ESP32-P4 datasheet](https://documentation.espressif.com/esp32-p4_datasheet_en.html)
- Espressif, [ESP32 datasheet](https://documentation.espressif.com/esp32_datasheet_en.pdf)
- Espressif, [ESP32 Ethernet Kit guide](https://documentation.espressif.com/esp-dev-kits/en/latest/esp32/esp32-ethernet-kit/user_guide_v1.1.html)
- Espressif, [ESP-IDF 6 Ethernet migration](https://docs.espressif.com/projects/esp-idf/en/stable/esp32s3/migration-guides/release-6.x/6.0/networking.html)
- Waveshare, [ESP32-S3-ETH-8DI-8RO](https://www.waveshare.com/wiki/ESP32-S3-ETH-8DI-8RO)
- OASIS, [MQTT Version 5.0 standard](https://docs.oasis-open.org/mqtt/mqtt/v5.0/mqtt-v5.0.html)
- Modbus Organization,
  [Modbus specifications](https://www.modbus.org/modbus-specifications)
- Modbus Organization,
  [Modbus Serial Line Protocol and Implementation Guide](https://www.modbus.org/file/secure/modbusoverserial.pdf)
- FreeRTOS, [Kernel developer documentation](https://freertos.org/Documentation/02-Kernel/02-Kernel-features/00-Developer-docs)
- FreeRTOS, [Queues](https://freertos.org/Documentation/02-Kernel/02-Kernel-features/02-Queues-mutexes-and-semaphores/01-Queues)
