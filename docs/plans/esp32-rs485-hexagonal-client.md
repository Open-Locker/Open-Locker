# ESP32 RS485 locker client: hexagonal implementation plan

Plan for [issue #56](https://github.com/Open-Locker/Open-Locker/issues/56) and
its sub-issues #57 (Wi-Fi provisioning), #58 (token entry and MQTT enrollment),
and #59 (OTA). It describes how to build ESP32 firmware structured the same way
as the Raspberry Pi client (`locker-client/`), and in which order.

The firmware is written new on a branch from `main`. This is a plan, not an
accepted decision.
Technical terms are explained in the glossary (section 8).

## 1. Starting point

### 1.1 What the issues ask for

| Issue | Ask |
| --- | --- |
| #56 | A parallel ESP32 client on the existing MQTT contract; the Pi path stays supported and available for rollback |
| #57 | First-boot Wi-Fi provisioning, persisted credentials, a way to change them |
| #58 | Provisioning token entry, MQTT enrollment, credential persistence, handling of invalid or expired tokens |
| #59 | OTA with two application slots, rollback, artifact hosting, documentation |

Two points in the issue text need a decision before work starts:

- #56 names Waveshare Modbus as the hardware contract. The bench hardware is
  the proprietary RS485 lock panel, which the Pi client already supports as
  `adapter_type = rs485_lock_board` (ADR-0061).
  [PR #312](https://github.com/Open-Locker/Open-Locker/pull/312) proposes making
  it the only adapter. The firmware supports the RS485 panel only and rejects
  any other `adapter_type`.
- #56 and #57 specify a SoftAP with captive portal for V1. The firmware is
  commissioned over USB serial first; the SoftAP is the last slice.

### 1.2 Bench hardware

- ESP32 D1 mini development board with onboard USB programming (PlatformIO
  board `wemos_d1_mini32`).
- XY-485 automatic-direction converter on the ESP32's 3V3, 3.3 V UART levels.
  There is no DE/RE line for the firmware to drive.
- RS485 locker control panel on its own supply; seller documentation specifies
  12 to 24 V. One lock on channel 1. The panel times the release pulse; the
  ESP32 only sends commands.
- USB data cable, four TTL-side wires, A/B pair from converter to panel.

Not established yet, and the firmware must not assume them: the panel's actual
supply voltage, exact panel model, physical channel count, lock model, flash
size of the board, the UART pins in use, the panel's serial parameters and DIP
address, and the feedback polarity.

### 1.3 How the Pi client is laid out

`locker-client/src/` follows ADR-0024:

| Layer | Contents | Rule |
| --- | --- | --- |
| `domain/` | `compartment`, `config`, `door-detection`, `door-feedback-mapping`, `errors`, MQTT value schemas | No I/O, no imports from other layers |
| `ports/` | `LockerBusPort`, `ConfigRepositoryPort`, `RuntimeOverlayStorePort`, `CredentialStorePort`, `DedupStorePort`, `OutboundMqttPort`, `MessageTransportPort`, `DoorEventPublisherPort`, `ClockPort`, `SchedulerPort`, `LoggerPort`, `TracingPort` | Interfaces only |
| `application/` | `OpenCompartmentUseCase`, `ApplyConfigUseCase`, `provisionDevice`, `HeartbeatUseCase`, `PollCompartmentStateUseCase` | Depends on domain and ports |
| `adapters/` | `mqtt/` (dispatcher, protocol guard, handlers, dedup store, envelope), `rs485/` (codec, driver, bus actor, serial transport), `config/`, `persistence/`, `simulator/` | Implement ports; own all wire formats |
| `bootstrap/` | `createApp.ts`, `createSimulatorApp.ts` | The only place that constructs adapters |

Three properties are copied exactly:

- The RS485 frame codec is an adapter, not domain. The application never sees a
  board address byte or a polarity bit.
- MQTT JSON is an adapter. Use cases return and accept typed values.
- A second composition root (`createSimulatorApp.ts`) runs the same application
  code against in-memory adapters. On the ESP32 that role is taken by host
  tests that compile the real domain and application code with fakes.

### 1.4 What does not carry over from the Pi

The contract is shared; the runtime is not. Four things have to be designed for
a microcontroller rather than ported:

| Pi client | ESP32 |
| --- | --- |
| Dedup store and overlay as JSON files (ADR-0046) | Append-only journal in a raw flash partition, plus NVS for small blobs |
| Configuration and token from `.env` and YAML | A commissioning channel on the device |
| Promise queues with priorities (`p-queue`) | FreeRTOS tasks with bounded queues |
| Docker image updates | OTA (#59), out of scope here |

### 1.5 Prerequisites

Before slice 1 starts:

1. PlatformIO Core, Node, CMake, and a host C compiler are installed. Nothing
   in this plan can be built or tested without them. PlatformIO is itself a
   Python program and needs Python installed; the project adds no Python code
   of its own. Project tooling is written for Node, which the repository
   already uses.
2. An unmodified ESP-IDF example builds for the board, proving the toolchain.
3. The facts from 1.2 that need no firmware are written down: panel model and
   label, measured supply voltage, channel count, lock model, board flash size,
   the UART pins wired to the converter, and the serial parameters and DIP
   address from the panel documentation. The pins and serial parameters become
   build defaults. Feedback polarity is captured in the hardware
   tests (HW-A4).

## 2. Approach

Use C on ESP-IDF, built through PlatformIO, with the toolchain versions pinned
in slice 1. Share the MQTT schemas in `docs/asyncapi/` and the RS485 golden
frames with the Pi client; share no code.

Work in vertical slices, test-first. The automated tests of every slice in the
first PR are written before the code; a slice adds one layer of capability and
is done when its tests pass. None of those tests needs hardware.
Everything that needs the real hardware is tested on the pull request, before
it merges (5.1).

Delivery is by pull request into `main` only, two PRs in total. No tags and no
releases are part of this plan.

### 2.1 Pull requests and branches

| # | PR | Branch |
| --- | --- | --- |
| 1 | This plan, the ADR-0005 update, slices 1 to 5, and their hardware tests: a complete firmware commissioned over USB | `feat/56-firmware-rs485-unlock` |
| 2 | Slice 6, SoftAP commissioning, and its hardware tests | `feat/57-firmware-softap` |

The slices stay the unit of work inside each PR:

- **Each slice is a run of commits.** Its tests exist before its
  implementation (4.1); the slice makes them pass. Commit messages name the
  slice, for example `feat(firmware): slice 2 command journal`.
- **A slice is finished before the next one starts.** Both gates in 4.1 pass at
  its last commit, and `verify.mjs` then counts it as implemented, so its
  tests are required from that commit on.
- **The PR description is the slice record.** It holds one row per slice with
  the status, the commit range, the gate result at the last commit, and any
  deviation from this plan. Reviewers go through the PR slice by slice in the
  commit view.
- **Both PRs are opened as drafts** and stay drafts while slices are added.
  A PR is marked ready when its last slice passes the gates and its hardware
  tests in 5.1 pass, and it merges after review.
- **Hardware-test fixes are further commits on the same PR**, each with a host
  test where the fault can be reproduced without hardware. The hardware
  acceptance record is committed on the PR as well.
- PR 2 is cut from `main` after PR 1 has merged. If work on it starts earlier,
  it is cut from the PR 1 branch and rebased onto `main` once PR 1 merges.
- The branch is brought up to date with `main` whenever `main` changes files
  the firmware build reads, such as `docs/asyncapi/`.
- The repository squash-merges, so `main` receives one commit per PR. The
  slice commits remain visible on the PR page.

### 2.2 Safety behaviours the firmware must prove

These follow from accepted decisions (ADR-0040, ADR-0041, ADR-0046, ADR-0048,
ADR-0050, ADR-0061) and from the proposed ADR-0067. Each is covered by named
host tests in the slice shown; section 4 lists them by ID.

| Behaviour | Slice |
| --- | --- |
| An unlock is never retried; the outcome is acknowledged, not sent, or unknown | 1 |
| A timeout or partial write after transmission still counts as a possible actuation for door detection | 1, 4 |
| No unlock frame is sent at startup or on reconnect; wire channel zero (open all) is never generated | 1 |
| After a request times out, the bus is kept silent for a settle period before the next request, so a late reply is discarded and not credited to the next request; where a reply cannot be attributed with certainty the outcome is unknown | 1 |
| The claim is durable before the unlock is sent; the result is durable before it is published; delivery is recorded only after PUBACK | 2 |
| A repeated transaction ID replays the stored response with a fresh message ID and sends nothing to the panel | 2 |
| A claim left incomplete by a restart becomes an unknown-outcome response, never a second unlock | 2 |
| A corrupt or torn journal record disables actuation | 2 |
| Running out of memory after transmission still leaves a durable final outcome | 2 |
| Journal capacity exhaustion refuses new work and keeps stored responses | 2 |
| `apply_config` cannot run during an in-flight unlock | 2 |
| The registration subscription is confirmed before the one-time token is published | 3 |
| A pending-enrollment flag is persisted before the token is published | 3 |
| A lost or rejected enrollment reply never triggers a second registration | 3 |
| Bootstrap credentials and token are cleared once issued credentials are saved | 3 |
| TLS certificate and hostname verification cannot be disabled | 3 |
| Missing status bits become `unknown`; repeated failed polls report `unknown` | 4 |
| Reconnect and `apply_config` force a snapshot without false door events | 4 |
| Malformed, oversized, NUL, or stalled commissioning input never saves partial settings | 5 |
| A network change on an enrolled device keeps identity, mapping, and journal | 5 |

## 3. Target architecture

### 3.1 Components

ESP-IDF components are the unit that can enforce dependencies, through
`REQUIRES` in each `CMakeLists.txt`. One component per layer, one per adapter:

```text
locker-firmware/
  components/
    lc_domain/            pure C11, no cJSON, no ESP-IDF, no FreeRTOS
    lc_ports/             header-only port structs
    lc_application/       use cases; REQUIRES lc_domain lc_ports
    lc_mqtt_codec/        JSON <-> domain values, envelope, fragment assembly (pure C + cJSON)
    lc_mqtt_espidf/       esp-mqtt transport; implements outbound and provisioning ports
    lc_rs485_lock_board/  frame codec + bus adapter; implements the locker bus port
    lc_serial_uart/       UART transaction transport for the bus adapter
    lc_journal/           append-only journal over a flash I/O struct; implements the command journal port
    lc_storage_nvs/       credential, settings, and runtime config stores
    lc_net_wifi/          Wi-Fi station, SNTP; implements clock and network ports
    lc_usb_console/       driving adapter: serial line protocol -> commissioning use case
  main/
    app_main.c            composition root: construct adapters, wire ports, start tasks
    owner_task.c          the single application task and its inbox
  tests/
    host/                 fakes + tests, one file per use case and per pure adapter
  scripts/                commissioning tool, bench and broker test tools
```

Allowed dependencies:

```text
lc_domain        <- nothing
lc_ports         <- lc_domain
lc_application   <- lc_domain, lc_ports
adapters         <- lc_domain, lc_ports, their own SDK
main             <- everything
```

An adapter never requires another adapter. Where two must cooperate, the
composition root passes one into the other through a small struct. The bus
adapter gets its serial transport this way, as `Rs485LockBoardDriver` gets
`Rs485TransactionTransport` on the Pi. That inner seam is also what lets the
bus adapter run on a host against a scripted serial fake.

### 3.2 Mapping from the Pi client

| Pi client | ESP32 firmware |
| --- | --- |
| `domain/compartment.ts`, `config.ts` | `lc_domain/compartment.h`, `config.c` (mapping validation, canonical hash input) |
| `domain/door-detection.ts` | `lc_domain/door_detection.c` (fire log, detection window, unknown-read streak) |
| `domain/errors.ts` | `lc_domain/errors.h` (error code enum; strings live in the codec) |
| `ports/locker-bus.port.ts` | `lc_ports/locker_bus_port.h` |
| `ports/mqtt.port.ts` `DedupStorePort` | `lc_ports/command_journal_port.h` |
| `ports/mqtt.port.ts` `OutboundMqttPort`, `door-events.port.ts` | `lc_ports/outbound_port.h` |
| `ports/config.port.ts` | `lc_ports/config_store_port.h`, `credential_store_port.h`, `clock_port.h` |
| `application/open-compartment.ts` | `lc_application/open_compartment.c` |
| `application/apply-config.ts` | `lc_application/apply_config.c` |
| `application/state-publishing.ts` | `lc_application/publish_state.c` |
| `application/provision-device.ts` | `lc_application/provision_device.c` |
| `adapters/mqtt/command-dispatcher.ts`, `inbound-protocol-guard.ts` | `lc_application/handle_command.c` |
| `adapters/mqtt/outbound-envelope.ts`, `domain/mqtt-parsing.ts` | `lc_mqtt_codec/` |
| `adapters/rs485/rs485-lock-board-codec.ts` | `lc_rs485_lock_board/codec.c` |
| `adapters/rs485/rs485-lock-board-bus-actor.ts` | `lc_rs485_lock_board/bus.c` |
| `adapters/rs485/serialport-transaction.transport.ts` | `lc_serial_uart/` |
| `adapters/mqtt/dedup-store.ts` | `lc_journal/` |
| `bootstrap/createApp.ts` | `main/app_main.c` |
| `bootstrap/createSimulatorApp.ts` | `tests/host/` composition with fakes |

One deliberate difference: the command guard moves into the application layer.
On the ESP32 the claim must be durable before any side effect and the journal is
the dedup store, so the ordering rule is application policy, not MQTT plumbing.

No commissioning use case exists on the Pi. `lc_application/commission_device.c`
is new.

### 3.3 Port shapes

Ports are structs of function pointers with a context pointer, one struct per
responsibility.

```c
/* lc_ports/locker_bus_port.h: protocol-neutral, names follow ADR-0067 */
typedef enum { LC_UNLOCK_ACKNOWLEDGED, LC_UNLOCK_NOT_SENT, LC_UNLOCK_OUTCOME_UNKNOWN } lc_unlock_result_t;
typedef struct { lc_unlock_result_t result; lc_door_t observed; } lc_unlock_observation_t;

typedef struct {
    void *ctx;
    bool (*apply_profile)(void *ctx, const lc_hardware_profile_t *profile);
    lc_unlock_observation_t (*unlock_compartment)(void *ctx, lc_target_t target);
    bool (*read_compartment_states)(void *ctx, uint8_t board, const uint8_t *addresses,
                                    size_t count, lc_door_t *out);
    lc_bus_state_t (*connection_state)(void *ctx);
} lc_locker_bus_port_t;
```

```c
/* lc_ports/outbound_port.h: typed messages, no topics, no JSON */
typedef struct {
    void *ctx;
    bool (*command_response)(void *ctx, const lc_command_result_t *result);
    bool (*heartbeat)(void *ctx, uint32_t uptime_seconds, bool bus_connected);
    bool (*compartment_snapshot)(void *ctx, const lc_compartment_state_t *states, size_t count);
    bool (*open_detection)(void *ctx, const lc_open_detection_t *event);
    bool (*uncommanded_open)(void *ctx, const lc_uncommanded_open_t *event);
    bool (*connection_offline)(void *ctx, lc_offline_reason_t reason);
} lc_outbound_port_t;
```

Outbound calls do not wait for the broker. A call returns true when the MQTT
adapter has accepted the message for sending. The broker's acknowledgement
(PUBACK) arrives later as an inbox event carrying the identity of the message,
and so does a failure or a timeout. Only the acknowledgement event marks a
command response as delivered in the journal, so "delivered" still means the
broker has the response, and the owner task keeps polling doors in the
meantime.

The other ports are `lc_command_journal_port_t` (find, seen, claim, complete,
delivered), `lc_config_store_port_t`, `lc_credential_store_port_t`,
`lc_provisioning_gateway_port_t` (subscribe reply, send registration),
`lc_clock_port_t` (monotonic milliseconds; wall time stays inside the MQTT
adapter for the envelope), `lc_hasher_port_t` (SHA-256), and
`lc_system_port_t` (restart).

Feedback polarity lives in the bus adapter and is set through `apply_profile`.
The application only ever sees `open`, `closed`, or `unknown`.

### 3.4 Task model

- One **owner task** runs every use case. Driving adapters never call a use
  case directly; they post to a bounded inbox (MQTT message, MQTT connected,
  subscribed, publish acknowledged, publish failed, console line, tick). This is what makes `apply_config` unable to
  race an unlock, and it is the only concurrency rule the application relies on.
- One **bus worker** behind the locker bus port owns the UART.
- The esp-mqtt task belongs to `lc_mqtt_espidf` and only copies and enqueues.

The owner task never waits for the network. The only thing it waits for is a
reply from the panel, which is bounded by the bus deadline, and during that
wait nothing else could use the bus anyway.

Every queue and buffer has a fixed capacity and a stated overflow policy.
Overflow never actuates: a dropped command gets no unlock and the backend times
out.

### 3.5 Command journal

The journal replaces the Pi's dedup file. It is an append-only record log in
its own flash partition, with a version and checksum per record and a commit
marker, read fully at startup into a bounded in-RAM index.

- A **claim** record carries message ID, transaction ID, and action, and is
  committed before any side effect.
- A **completion** record carries the typed command result and is committed
  before the response is published.
- A **delivered** record is committed when the PUBACK event for that response
  arrives.

Retention limits, compaction, and behaviour on a full partition are a
reliability trade-off and get their own ADR in slice 2. The first version is
bounded and conservative: no pruning by wall-clock time, no automatic erase.

### 3.6 Where the sub-issues land

- **#58 enrollment** is `provision_device.c`: subscribe to the reply topic,
  persist the pending flag, publish the registration, persist issued
  credentials, clear bootstrap material. It is host-testable, including the
  lost-reply path.
- **#57 Wi-Fi provisioning** is `commission_device.c` (validate, persist,
  acknowledge, restart) with `lc_usb_console` as its first driving adapter
  (slice 5). The SoftAP portal is a second driving adapter over the same use
  case (slice 6) and adds no application code.
- **#59 OTA** gets a `lc_firmware_update_port_t` seam and nothing else in this
  plan. It needs its own ADR (signing, rollback, readiness, cohorts) and depends
  on the update command designed in #70.

## 4. Slices

Each slice is a run of commits on its PR (2.1), containing its code and its
tests. Estimated size of the whole firmware: 2,000 to
2,500 lines of C, 1,500 to 2,000 lines of host tests, and a few hundred lines of
Node tooling. These are estimates, not measurements.

### 4.1 Gates every slice must pass

A slice is finished only when both gates pass at its last commit. CI runs both
gates on every push, with every suite implemented so far. Neither gate needs
hardware.

| Gate | What runs | Where |
| --- | --- | --- |
| **Static** | Target build with warnings as errors; layer include check; compiler static analysis on the pure components | CI, required |
| **Host** | Every host suite listed below, built with AddressSanitizer and UndefinedBehaviorSanitizer; schema validation of every emitted MQTT message; line coverage report | CI, required |

Rules:

- One command runs the static and host gates locally and in CI:
  `node scripts/verify.mjs`. CI runs it in a `locker-firmware-ci` workflow that
  is a required check.
- Host suites use Unity, the C test framework that ships with ESP-IDF, built by
  a host CMake project under `tests/host/`. One file per suite, one function
  per test, named as in the tables below.
- Host suites compile the real component sources. Only ports are faked. The
  fakes live in `tests/host/fakes/` and record call order, so ordering rules
  are asserted, not assumed.
- **The tests of slices 2 to 5 are already written.** They were committed
  together, after slice 1, with the headers that declare the interfaces they
  call. Until its slice is implemented a suite is *pending*: the host build
  compiles it against those headers, so it cannot drift, but does not link or
  run it, because the code it tests does not exist. Pending suites are listed
  with `lc_pending_test()` in `tests/host/CMakeLists.txt`.
- **A slice turns its suites on and makes them pass.** It changes each
  `lc_pending_test()` line of its suites to `lc_host_test()`, adds its sources
  to the build, and implements until every test passes. It does not edit the
  tests or the interfaces to get there. Where a test or an interface turns out
  to be wrong, the change is a commit of its own and is listed, with the
  reason, under "Test changes" in the slice record.
- The tests of slice 6 depend on the portal security design and are written
  at the start of that slice, before its implementation.
- **Encoded messages are checked against the AsyncAPI schemas.** The tests
  that encode a message hand it to `emit_message()`, which appends it to the
  file named by `LC_EMITTED_MESSAGES`. The host gate validates every line of
  that file against `docs/asyncapi/schemas`; slice 2 adds that step.
- **Plan IDs in test names.** Each test function carries its ID, for example
  `test_S2_H27_claim_committed_before_bus_call`. A script fails the build
  when a plan ID of a slice implemented so far has no test, or a test carries
  an ID this document does not list.
- A slice is complete when every test listed for that slice runs and passes.
  Tests for details this plan does not list are written first too, in the
  slice that needs them, without a plan ID.
- A test from an earlier slice is changed only with a line in the PR
  description saying what changed and why.
- Line coverage of `lc_domain`, `lc_application`, and the pure adapters
  (`lc_mqtt_codec`, `lc_rs485_lock_board`, `lc_journal`) must be at least 90%
  for every component implemented so far.
- A test that fails intermittently is a failing test. No test is removed or
  skipped without a line in the PR saying why.
- Hardware checks are not part of any slice. They gate the PR, not the slice,
  and are listed in 5.1.

### Slice 1: skeleton, domain, and one unlock

The first commits of PR 1 also add this plan document to the repository.

Scope:

- Edit ADR-0005 in place; it is still "Proposed", so no superseding ADR is
  needed. It currently selects the Waveshare `ESP32-S3-ETH-8DI-8RO` with
  onboard relays, extended by Waveshare Modbus boards. The edit states that the
  ESP32 client is developed against the RS485 lock panel on an ESP32 D1 mini
  with an XY-485 converter, that this is bench hardware and not a production
  board selection, and links this plan. The status stays "Proposed".
- Project scaffold: pinned platform and ESP-IDF versions, partition table with
  a journal partition, warnings as errors, watchdog enabled.
- `scripts/verify.mjs`, the host CMake project, the fakes directory, the layer
  include check, the plan-ID check, and the CI workflow.
- `lc_domain`: compartment, target, door state, hardware profile, error codes.
- `lc_ports`: `lc_locker_bus_port_t`, `lc_clock_port_t`.
- `lc_rs485_lock_board` (codec and bus adapter) and `lc_serial_uart`, with UART
  number, pins, baud, framing, quiet interval, reply deadline, and settle
  period in Kconfig.
- `lc_usb_console` with `status`, `diagnostics`, `self-test`, and two bench
  commands, `unlock <board> <channel>` and `query <board>`, that call the port.

Host suite `test_rs485_codec`:

| ID | Test |
| --- | --- |
| S1-H01 | Unlock for board 1, address 0 encodes as `8A 01 01 11 9B` |
| S1-H02 | Query-all for board 1 encodes as `80 01 00 33 B2` |
| S1-H03 | Board addresses 0 and 32 are rejected |
| S1-H04 | Address 255 is rejected; no input produces wire channel zero |
| S1-H05 | Unlock acknowledgement with status `00` and with status `11` is accepted |
| S1-H06 | Unlock acknowledgement with any other status is rejected |
| S1-H07 | Unlock acknowledgement for another board or channel is rejected |
| S1-H08 | The verified response `80 01 00 00 00 33 B2` decodes |
| S1-H09 | Status bits map to addresses in the documented order, for both feedback polarities |
| S1-H10 | Addresses beyond the returned status bytes decode as `unknown` |
| S1-H11 | Bad checksum, wrong header, wrong board, wrong command marker, and frames under five bytes are rejected |

Host suite `test_rs485_bus`, against a scripted serial fake:

| ID | Test |
| --- | --- |
| S1-H12 | Starting the adapter writes nothing |
| S1-H13 | Acknowledged unlock returns `ACKNOWLEDGED` after exactly one write |
| S1-H14 | A write that sends zero bytes returns `NOT_SENT` |
| S1-H15 | A partial write returns `OUTCOME_UNKNOWN` |
| S1-H16 | No reply before the deadline returns `OUTCOME_UNKNOWN` after exactly one write |
| S1-H17 | A reply with a bad checksum returns `OUTCOME_UNKNOWN` after exactly one write |
| S1-H18 | Bytes already in the receive buffer are drained and cannot acknowledge the next request |
| S1-H19 | A frame is complete only after the quiet interval; a reply split across reads is assembled |
| S1-H20 | A reply longer than the frame buffer is rejected without overflow |
| S1-H21 | A failed state read reports every requested address as `unknown` and the bus as not connected |
| S1-H22 | Repeated failures start a cooldown during which nothing is written |
| S1-H23 | An unlock requested during cooldown returns `NOT_SENT` |
| S1-H24 | The first successful exchange after cooldown reports the bus as connected |
| S1-H27 | After a request times out, nothing is written until the settle period has passed, and bytes received in it are discarded |
| S1-H28 | Request A to a channel times out; request B to the same channel follows; A's acknowledgement arrives late: B is not reported as acknowledged by it |
| S1-H29 | A reply that arrives while the write is still completing (a UART returns only after the last bit left) is accepted: an unlock is `ACKNOWLEDGED`, a status query is answered |
| S1-H30 | An unlock requested during the settle period waits for it to end and is then sent once |
| S1-H31 | A late status reply to a timed-out query is not used as the answer to the next query |

Host suite `test_console_line`:

| ID | Test |
| --- | --- |
| S1-H25 | `unlock` and `query` parse valid arguments and reject out-of-range ones |
| S1-H26 | A line over the length limit is discarded through its newline and nothing is executed |

### Slice 2: commands, journal, and MQTT codec

Scope:

- `lc_journal` behind `lc_command_journal_port_t`, and its ADR (3.5).
- `lc_mqtt_codec`: inbound payload to `lc_command_t`, typed message to JSON plus
  envelope, fragment assembly with a payload size limit.
- `lc_application`: `handle_command.c`, `open_compartment.c`, `apply_config.c`,
  recovery after restart, and the owner inbox step function.
- A console command that feeds one command JSON line into the owner inbox,
  used by the hardware tests.

No radio yet.

Host suite `test_mqtt_codec`:

| ID | Test |
| --- | --- |
| S2-H01 | Every command example in `docs/asyncapi/examples/` parses into the expected typed command |
| S2-H02 | A payload without `transaction_id` is dropped with no response |
| S2-H03 | A payload without `message_id` yields a `MISSING_MESSAGE_ID` response |
| S2-H04 | Invalid timestamp, non-object `data`, and missing `compartment_number` yield `INVALID_COMMAND` |
| S2-H05 | An unknown action yields `UNKNOWN_ACTION` |
| S2-H06 | Nesting deeper than the limit, duplicate keys, embedded NUL, and IDs over the length limit are rejected |
| S2-H07 | Fragments arriving in order assemble into one payload |
| S2-H08 | A gap, an overlap, or a total over the payload limit discards the message |
| S2-H09 | Every outbound message carries a fresh UUID `message_id` and a UTC `timestamp` |
| S2-H10 | Each emitted response validates against its schema in `docs/asyncapi/schemas` |

Host suite `test_config`:

| ID | Test |
| --- | --- |
| S2-H11 | The canonical hash of `command-apply-config.json` equals its `config_hash` |
| S2-H12 | Compartments are sorted by number before hashing; input order does not change the hash |
| S2-H13 | A hash mismatch is rejected |
| S2-H14 | Duplicate compartment numbers and duplicate addresses are rejected |
| S2-H15 | `slaveId` 0 and 32, and `address` 255, are rejected |
| S2-H16 | A second board address is rejected |
| S2-H17 | `adapter_type` other than `rs485_lock_board` is rejected |
| S2-H18 | More compartments than the configured maximum are rejected |

Host suite `test_journal`, against an in-memory flash fake:

| ID | Test |
| --- | --- |
| S2-H19 | A claim is found by transaction ID and its message ID is marked seen |
| S2-H20 | Claim, completion, and delivered records survive closing and reopening |
| S2-H21 | A write cut at every byte boundary of a claim leaves either no claim or an unhealthy journal, never a usable half record |
| S2-H22 | The same for a completion record and for a delivered record |
| S2-H23 | A record with a bad checksum marks the journal unhealthy |
| S2-H24 | A claim is refused when there is no room left for its completion and delivered records |
| S2-H25 | A full journal refuses new claims and still returns every stored response |
| S2-H26 | Reopening never erases or rewrites existing records |

Host suite `test_handle_command`, with call-order recording fakes:

| ID | Test |
| --- | --- |
| S2-H27 | The claim is committed before the bus is called |
| S2-H28 | The completion is committed before the response is published |
| S2-H29 | Delivered is committed only when the acknowledgement event for that response arrives, not when the publish is accepted |
| S2-H30 | A publish that is refused, fails, or is never acknowledged leaves the response pending; a later flush publishes it once |
| S2-H31 | A repeated message ID is ignored: no bus call, no response |
| S2-H32 | A repeated transaction ID with a new message ID replays the stored response with a fresh message ID and no bus call |
| S2-H33 | A repeated transaction ID while the first is incomplete produces no bus call |
| S2-H34 | An unhealthy journal yields an error response and no bus call |
| S2-H35 | A refused claim yields an error response and no bus call |
| S2-H36 | Recovery turns an incomplete claim into an unknown-outcome response with no bus call |
| S2-H37 | Running recovery twice produces one completion |
| S2-H38 | With the response refused after the bus call, as when no memory is left to encode it, a final outcome is still committed; a journal that cannot commit it keeps the claim and never unlocks again |
| S2-H39 | While closing, a new command yields `SHUTTING_DOWN` and no bus call |

Host suites `test_open_compartment`, `test_apply_config`, `test_owner_inbox`:

| ID | Test |
| --- | --- |
| S2-H40 | Open before any configuration yields `RUNTIME_CONFIG_NOT_APPLIED` |
| S2-H41 | Open of an unmapped compartment yields `COMPARTMENT_NOT_FOUND` |
| S2-H42 | `ACKNOWLEDGED` yields success |
| S2-H43 | `NOT_SENT` yields `HARDWARE_ERROR` and arms no door detection |
| S2-H44 | `OUTCOME_UNKNOWN` yields `HARDWARE_ERROR` and arms door detection |
| S2-H45 | The bus is called with the mapped board and address, exactly once |
| S2-H46 | A valid `apply_config` is saved, sets the bus profile, and responds with `applied_config_hash` |
| S2-H47 | A valid `apply_config` is saved and acknowledged while the bus is disconnected |
| S2-H48 | A store failure yields an error and the previous configuration stays in effect |
| S2-H49 | A rejected `apply_config` leaves the previous configuration in effect |
| S2-H50 | Inbox items run strictly one at a time, in arrival order; an `apply_config` queued behind an open runs only after the open has completed |
| S2-H51 | A full inbox drops the new item with no bus call |
| S2-H52 | While an acknowledgement is outstanding, the owner task goes on processing inbox items |
| S2-H53 | An acknowledgement event for an unknown or already delivered response changes nothing |

Ported cases: the contract-level tests from
`locker-client/tests/unit/command-dispatcher.test.ts`,
`tests/application/open-compartment.test.ts`, and `apply-config.test.ts` are
listed by name in the PR, each marked as covered by an ID above or as not
applicable with a reason.

### Slice 3: network, MQTT, and enrollment (#58)

Scope:

- `lc_net_wifi` (station, SNTP) and `lc_mqtt_espidf` (verified TLS, MQTT 3.1.1,
  persistent session, QoS 1, stable client ID, Last Will, publish that returns
  after PUBACK).
- `lc_storage_nvs` for settings and credentials.
- `lc_application/provision_device.c` and the session state machine, with
  `lc_provisioning_gateway_port_t` and `lc_credential_store_port_t`.
- The owner task in `main/`.
- `scripts/broker-check.mjs`, which sends `apply_config` and, only with an
  explicit flag, one open plus duplicates.
- A minimal `configure` console command for network settings, hardened in
  slice 5.

Host suite `test_provision_device`:

| ID | Test |
| --- | --- |
| S3-H01 | The registration is published only after the reply subscription is confirmed |
| S3-H02 | The pending flag is saved before the registration is published |
| S3-H03 | If saving the pending flag fails, nothing is published |
| S3-H04 | A success reply saves the issued credentials and locker UUID, clears token and bootstrap credentials, and clears the pending flag, in one store write |
| S3-H05 | A rejected or expired token reports the failure and publishes no second registration |
| S3-H06 | A reply that never arrives publishes no second registration and leaves the pending flag set |
| S3-H07 | A device that boots with the pending flag set never publishes a registration |
| S3-H08 | A malformed reply, a reply with an invalid locker UUID, and a reply on another topic are ignored |
| S3-H09 | If saving credentials fails, the device does not consider itself enrolled |
| S3-H10 | An enrolled device never publishes a registration |

Host suite `test_session`:

| ID | Test |
| --- | --- |
| S3-H11 | Commands received before the command subscription is confirmed are not executed |
| S3-H12 | Topics are built from the locker UUID, not the MQTT username |
| S3-H13 | On every reconnect, pending responses are flushed and a snapshot is forced |
| S3-H14 | Nothing is published while the transport reports offline |
| S3-H15 | The Last Will payload and the orderly offline message validate against their schemas |
| S3-H16 | A message on any topic other than the command topic is not dispatched |

### Slice 4: state and door events

Scope: `lc_application/publish_state.c` (heartbeat, 500 ms poll, retained
change-only snapshot) and `lc_domain/door_detection.c` (fire log, detection
window, unknown-read streak, uncommanded open).

Host suite `test_door_detection`:

| ID | Test |
| --- | --- |
| S4-H01 | A door seen open inside the window yields `opened` with the elapsed time |
| S4-H02 | A door never seen open yields `door_jammed` when the window ends, once |
| S4-H03 | `unknown` readings inside the window count as neither open nor jammed |
| S4-H04 | A mapping change during detection cancels it without an event |
| S4-H05 | Closed to open with no recent unlock yields an uncommanded-open event |
| S4-H06 | Closed to open during or just after a commanded open yields no uncommanded-open event |
| S4-H07 | The first reading after boot being open yields no event |
| S4-H08 | `unknown` to open yields no uncommanded-open event |
| S4-H09 | An unlock that returned `OUTCOME_UNKNOWN` still runs detection and can report `opened` |
| S4-H10 | A detection event whose publish fails is retried and published once |

Host suite `test_publish_state`:

| ID | Test |
| --- | --- |
| S4-H11 | No polling and no snapshot before configuration is applied |
| S4-H12 | A snapshot is published when a state changes and not otherwise |
| S4-H13 | A snapshot is forced after reconnect and after `apply_config`, with no door events |
| S4-H14 | The snapshot is published retained; a failed publish is retried on the next poll |
| S4-H15 | One or two failed reads keep the last state; the third reports `unknown` |
| S4-H16 | A status response shorter than the mapping reports the uncovered compartments as `unknown` |
| S4-H17 | The heartbeat is sent at the configured interval, and at the default before configuration |
| S4-H18 | A changed interval from `apply_config` takes effect on the next heartbeat |
| S4-H19 | The heartbeat reports the bus as disconnected while it is in cooldown |
| S4-H20 | A poll and an unlock never overlap on the bus port |
| S4-H21 | Heartbeat, snapshot, and all three event messages validate against their schemas |
| S4-H22 | With the acknowledgement of the unlock response delayed past the detection window, a door that opens and closes in the meantime is reported as `opened`, not `door_jammed` |
| S4-H23 | With an acknowledgement outstanding, an uncommanded open is still detected |

Ported cases: `locker-client/tests/application/state-publishing.test.ts` and
`heartbeat-hardware-state.test.ts`, listed in the PR as in slice 2.

### Slice 5: USB commissioning (#57)

Scope:

- `lc_application/commission_device.c`: validate, persist, acknowledge, restart;
  network reset; orderly offline publish before restart.
- `lc_usb_console` line protocol completed and bounded, and
  `scripts/commission.mjs`, which prompts for settings with hidden secret input.
- `main/app_main.c` reduced to construction and wiring.
- README: wiring, build, commissioning, limits, recovery, and returning a bank
  to the Pi client.

Host suite `test_commission_device`:

| ID | Test |
| --- | --- |
| S5-H01 | Valid settings are saved, then acknowledged, then the restart is requested, in that order |
| S5-H02 | An SSID or Wi-Fi password of invalid length, or a broker URL that is not `mqtts://`, is rejected and nothing is saved |
| S5-H03 | A store failure produces no acknowledgement and no restart |
| S5-H04 | On an enrolled device, a network change keeps credentials, locker UUID, mapping, and journal |
| S5-H05 | On an enrolled device, token and bootstrap fields in the input are ignored |
| S5-H06 | A device with the pending flag set refuses new settings until it is reset |
| S5-H07 | Reset clears identity, credentials, and mapping, and keeps the journal |
| S5-H08 | When online, the offline message is published before the restart is requested |
| S5-H09 | New commands are refused once the settings are stored, before the restart; a failed save leaves the device taking commands |

Host suite `test_console_protocol`:

| ID | Test |
| --- | --- |
| S5-H10 | An oversized line is discarded through its newline; its tail is never executed as a command |
| S5-H11 | A line containing NUL or invalid UTF-8 is rejected |
| S5-H12 | A partial line idle past the timeout is discarded |
| S5-H13 | An unknown command gets an error reply and changes nothing |
| S5-H14 | No reply contains any submitted value |
| S5-H15 | Input processed per pass is bounded, so a flood cannot starve the inbox |

Tool suite `commission.test.mjs` (Node test runner, runs in the host gate):

| ID | Test |
| --- | --- |
| S5-H16 | Secrets are read without echo and never written to disk or to output |
| S5-H17 | A lost serial connection after sending is reported; the tool does not resend |
| S5-H18 | The tool never issues a reset on its own |

### Slice 6: SoftAP commissioning (#57)

Scope:

- `lc_softap_portal`: a second driving adapter over `commission_device.c`. The
  device starts an access point with a captive portal when it has no Wi-Fi
  credentials, and on a deliberate local reset.
- An ADR for the portal's security: how the access point is protected, how the
  provisioning token is entered, when the access point shuts down, and what
  physical action reopens it.
- A decision on what remains of the USB path. Recommended: remove USB commissioning of secrets but keep the
  read-only console commands (`status`, `diagnostics`, `self-test`) for
  recovery and bench work.

This slice is PR 2. The portal ADR is its first commit, because the tests
depend on it.

Host suite `test_softap_portal`, on the portal's request handling and state
machine:

| ID | Test |
| --- | --- |
| S6-H01 | A valid form submission reaches `commission_device` with the same values a USB submission would |
| S6-H02 | A body over the size limit, a missing field, and a malformed encoding are rejected and nothing is saved |
| S6-H03 | A request without the portal credential defined in the ADR is rejected |
| S6-H04 | No response contains any submitted value |
| S6-H05 | The portal starts when no Wi-Fi credentials are stored |
| S6-H06 | The portal does not start on a device that is enrolled and online |
| S6-H07 | The portal stops once settings are accepted |
| S6-H08 | A Wi-Fi connection that fails after commissioning brings the portal back and keeps identity |
| S6-H09 | The portal never exposes status, diagnostics, or stored settings |
| S6-H10 | S5-H01 to S5-H09 pass unchanged |

## 5. Hardware tests and follow-up

### 5.1 Hardware tests

Everything that needs the real board, converter, panel, lock, Wi-Fi, or broker
is tested here, on the hardware in 1.2. No slice waits for it, but each PR
does: a PR merges only when its hardware tests pass.

| PR | Hardware tests before it merges |
| --- | --- |
| 1 | Groups A to E, then Group G |
| 2 | Group F, then the regression checks HW-A1, HW-A6, HW-C4, HW-D2, and HW-D4 |

Rules:

- Run the groups in order. A failure in an earlier group makes later results
  unreliable; stop, fix, and restart from the failed group.
- Every fix is a commit on the same PR, which must pass the static and host
  gates. Where the fault can be reproduced without hardware, the commit adds a
  host test for it.
- Record each check in a hardware acceptance document, committed on the PR,
  with the source commit, the firmware SHA-256, the operator, and the date. A
  check that was not run is marked as not run, never as passed.
- Checks marked **actuates** release the lock and need someone at the bench.
- A group may be run informally as soon as its slice is finished. Only a run
  against the final commit of the PR counts for the merge.

Group A, serial bus and panel (verifies slice 1):

| ID | Check |
| --- | --- |
| HW-A1 | Power-cycle the ESP32 three times while idle: the unlock transmission counter stays at zero and the lock does not move |
| HW-A2 | `self-test` passes and sends nothing to the panel |
| HW-A3 | `query 1` returns a valid frame |
| HW-A4 | Capture one query-all frame with the door open and one closed; record bit position and polarity |
| HW-A5 | Transmit with the panel unpowered and confirm no bytes return on RX (the converter does not echo) |
| HW-A6 | **Actuates.** `unlock 1 0` releases the channel 1 lock exactly once |
| HW-A7 | Record the measured time from unlock request to acknowledgement, and confirm it is inside the configured deadline |
| HW-A8 | Remove panel power, confirm the bus reports disconnected, restore it, confirm recovery without restarting the ESP32 |
| HW-A9 | Measure the reply time of 200 unlocks and 200 queries; confirm the longest is below the reply deadline, and that the settle period exceeds it with margin |

Group B, commands and journal (verifies slice 2):

| ID | Check |
| --- | --- |
| HW-B1 | **Actuates.** Feed `apply_config` then one open over the console: the lock releases once and the response is printed |
| HW-B2 | Feed the same open again, once with the same message ID and once with a new one: the unlock transmission counter does not change |
| HW-B3 | Restart with configuration applied: the mapping is still in effect |
| HW-B4 | **Actuates.** Cut ESP32 power immediately after feeding an open, ten times: after each restart the transaction has a stored outcome and no unlock is sent during boot |
| HW-B5 | **Actuates.** Cut ESP32 power at claim, after transmission, at completion, and before PUBACK: each case ends in a stored outcome and no second unlock |
| HW-B6 | Fill the journal on a dedicated test unit: new commands are refused and earlier responses are still replayed |

Group C, network, MQTT, and enrollment (verifies slice 3):

| ID | Check |
| --- | --- |
| HW-C1 | Enroll a fresh unit against the real backend; the locker bank shows as connected |
| HW-C2 | Point the device at a broker with a certificate for another hostname, then at a self-signed one: it refuses both |
| HW-C3 | `broker-check.mjs` without the actuation flag: `apply_config` is acknowledged with the matching hash |
| HW-C4 | **Actuates.** One open from the admin panel releases the lock once |
| HW-C5 | **Actuates.** `broker-check.mjs` with the actuation flag: one open and two duplicates raise the unlock transmission counter by one |
| HW-C6 | Switch the access point off for one minute, then the broker for one minute: the device reconnects unaided and a response pending at the time is delivered |
| HW-C7 | Use an invalid token: the device reports rejection and does not retry; after a backend provisioning reset and a fresh token it enrolls |
| HW-C8 | Pull power from the ESP32: the backend shows the bank offline through the Last Will |
| HW-C9 | Record minimum free heap and owner task stack headroom after HW-C6 |

Group D, state and door events (verifies slice 4):

| ID | Check |
| --- | --- |
| HW-D1 | Open and close the door by hand: the admin panel and the mobile app follow within two seconds |
| HW-D2 | **Actuates.** Open through the backend and pull the door: an `opened` event arrives |
| HW-D3 | **Actuates.** Open through the backend and hold the door shut: `door_jammed` arrives after the window |
| HW-D4 | Force the door open by hand with no command: an uncommanded-open event arrives |
| HW-D5 | Remove panel power: the heartbeat reports the bus as disconnected and states become `unknown`; restore it and both recover |
| HW-D6 | Restart the broker with the door open: no door event is published on reconnect |

Group E, USB commissioning (verifies slice 5):

| ID | Check |
| --- | --- |
| HW-E1 | **Actuates.** Commission a fully erased unit with `commission.mjs` through to an open from the admin panel |
| HW-E2 | Enter wrong Wi-Fi credentials, then correct them over USB without a reset: identity is kept |
| HW-E3 | Move an enrolled unit to another Wi-Fi network: identity, mapping, and journal are kept |
| HW-E4 | Unplug USB during a save, ten times: the device has either the old or the new settings, never a mix |
| HW-E5 | Reset the unit: the old credentials no longer work, and a fresh token enrolls it |

Group F, SoftAP commissioning (verifies slice 6):

| ID | Check |
| --- | --- |
| HW-F1 | **Actuates.** Commission a fully erased unit from a phone with no cable attached, through to an open from the admin panel |
| HW-F2 | Enter wrong Wi-Fi credentials: the portal returns and identity is kept |
| HW-F3 | Once the device is enrolled and online, scan for the access point and probe the HTTP port from the same network: neither is reachable |
| HW-F4 | The physical action defined in the portal ADR reopens the portal; nothing else does |
| HW-F5 | With the portal open on a previously enrolled unit, send an open through the backend: behaviour matches the portal ADR |
| HW-F6 | If USB commissioning was removed, `configure` over USB is refused and the read-only commands still work |

Group G, endurance and rollback:

| ID | Check |
| --- | --- |
| HW-G1 | **Actuates.** Run for 72 hours with an open every ten minutes: no missed response, no reset, and minimum free heap is stable between the first and last hour |
| HW-G2 | Return the bank to the Pi client with a provisioning reset and fresh credentials, with the ESP32 disconnected from the panel |

### 5.2 Todo: update the issues

When PR 1 merges:

- #56: RS485 lock panel instead of Waveshare Modbus; link the hardware acceptance
  document.
- #57: USB commissioning is delivered; the SoftAP follows in PR 2.
- #58: close against slice 3 and the Group C results.

When PR 2 merges:

- #57: close against slice 6 and the Group F results, stating what remained of
  the USB path.

### 5.3 Later, each behind its own decision

- OTA (#59): two application slots, signed images, rollback, and the control
  plane from #70. It needs a measured image size against the board's flash and
  the journal partition.
- More than one panel per bus.
- Secure boot, flash encryption, encrypted credentials at rest, journal
  compaction, trusted time, and the pilot gates listed in #56.

## 6. Decisions

- **Commissioning.** USB serial first (slice 5). The SoftAP portal is the last
  slice (slice 6), which also decides how much of the USB path stays.
- **Hardware scope.** The RS485 lock panel is the only supported locker
  protocol. One panel per bus in this plan.
- **Port naming.** The locker bus port uses the names proposed in ADR-0067
  (PR #312): unlock a compartment, read compartment states, board address. This
  does not depend on that PR merging.
- **Language.** C on ESP-IDF. Rust was considered and not chosen: the official
  Espressif Rust SDK does not yet cover Wi-Fi, MQTT, storage, and OTA, and the
  wrappers that do are community-maintained.
- **No shared core.** The Pi client stays TypeScript and the firmware shares the
  MQTT schemas and RS485 golden frames with it, not code.
- **Directory name.** `locker-firmware/`.

Nothing is open. These decisions are recorded here and get no separate ADR.
The two design decisions still to be made, the journal (slice 2) and the portal
security (slice 6), each get an ADR in their slice.

## 7. Risks

- **RAM.** The journal index, the MQTT reassembly buffer, and TLS all compete
  for a few hundred kilobytes. Size the limits in slice 2 against a budget;
  the measured values come from HW-C9 and HW-G1.
- **Late replies on the bus.** The panel protocol has no request identifier,
  so a reply that arrives after its request timed out cannot be told apart from
  the reply to the next request. The settle period (S1-H27 to S1-H31) covers
  this only if it is longer than the panel's worst reply time, which is
  unmeasured until HW-A9. A reply later than the settle period can still be
  misattributed; on the same channel that means a wrong report, not a wrong
  door.
- **Flash behaviour under power loss.** Host tests cut journal writes at record
  boundaries; real flash can fail differently. HW-B4 and HW-B5 test it on the
  device.
- **Drift from the Pi client.** Two implementations of one contract. The shared
  schemas, shared golden frames, and the ported test names are the controls;
  there is no shared code.
- **Hardware problems found late.** Five slices are written before the
  firmware has to pass on the real panel. A wrong assumption about the
  protocol, timing, or wiring may reach back into slice 1. Nothing has merged
  at that point, so the fix lands on the same PR; running Group A informally as
  soon as slice 1 is finished keeps the rework small.
- **A large first PR.** PR 1 carries most of the firmware. Review depends on
  the slice record and on going through it commit by commit.
- **Unverified hardware facts.** Everything listed as not established in 1.2
  stays open until recorded in the prerequisites and in HW-A4. Host tests
  establish none of it.
- **Plaintext credentials.** Until flash encryption is decided, Wi-Fi and MQTT
  credentials sit unencrypted in flash. This limits the firmware to an attended
  bench.

## 8. Glossary

| Term | Meaning |
| --- | --- |
| Adapter | In hexagonal architecture, the code that connects the application to one outside technology (MQTT, the serial bus, flash storage). It implements a port. |
| ADR | Architecture Decision Record: a short document in `docs/adr/` recording one decision, its alternatives, and its consequences. |
| AsyncAPI | The specification format, in `docs/asyncapi/`, that defines the MQTT topics and message shapes. The contract both clients follow. |
| Baud rate | Speed of a serial line in bits per second. Both ends must use the same value. |
| Bench | The test setup on a desk: board, converter, panel, and one lock. |
| Broker | The MQTT server that all messages pass through. Devices and the backend never talk to each other directly. |
| Captive portal | The web page a phone opens automatically after joining a setup Wi-Fi network. |
| Checksum | A value computed from a message and sent with it, so the receiver can detect corruption. The panel protocol uses an XOR of all bytes. |
| CI | Continuous integration: the automated checks that run on every pull request. |
| Commissioning | Giving a new device its local settings: Wi-Fi name and password, broker address, provisioning token. |
| Composition root | The one place where all adapters are created and connected to the application. Here `main/app_main.c`. |
| Cooldown | A pause after repeated bus failures during which the firmware does not try again. |
| DE/RE | The control pins that switch an RS485 converter between sending and receiving. The XY-485 switches by itself, so the firmware has none to drive. |
| DIP switch | Small physical switches on the panel that set its address on the bus. |
| Domain | The layer holding the rules and data types of the problem (compartment, door state, door detection), with no knowledge of any technology. |
| Driving adapter | An adapter that brings input into the application (an MQTT command, a console line), as opposed to one the application calls. |
| Enrollment | The exchange in which a device presents its one-time provisioning token and receives its permanent MQTT credentials. Also called provisioning. |
| ESP32 | The microcontroller family used here: a small chip with Wi-Fi, a few hundred kilobytes of memory, and no operating system in the PC sense. |
| ESP-IDF | The official software development kit for the ESP32 from its maker, Espressif. |
| Fake | A test stand-in for a port that records what was called and returns scripted results. |
| Feedback polarity | Whether a 1 from the panel's door sensor input means closed or open. It depends on the lock and wiring. |
| Firmware | The program that runs on the microcontroller. |
| Flash | The chip's permanent storage. It can be appended to cheaply, but changing data means erasing a whole block, and blocks wear out. |
| Fragment | One piece of an MQTT message that arrived split across several reads. |
| Frame | One complete message on the serial bus, for example the five bytes of an unlock command. |
| FreeRTOS | The small operating system inside ESP-IDF. It provides tasks and queues. |
| Golden frame | A byte sequence captured from real hardware and used as a fixed expected value in tests. |
| Heap | Memory allocated while the program runs. Running out of it is a failure mode on a microcontroller. |
| Heartbeat | A periodic MQTT message saying the device is alive and whether it can reach the panel. |
| Hexagonal architecture | A structure in which the domain and use cases sit in the middle and reach every technology only through ports. Also called ports and adapters. |
| Host test | A test that compiles the firmware's C code for a normal computer and runs it there, with no ESP32 involved. |
| Inbox | The fixed-size queue in front of the owner task. |
| Journal | The append-only log in flash that records every command before and after it is acted on. See 3.5. |
| Kconfig | The build-time configuration system of ESP-IDF, used here for pins and serial settings. |
| Last Will | A message a device registers with the broker in advance. The broker publishes it if the device disappears without saying goodbye. |
| Message ID | An identifier unique to one transmission of a message. A resend of the same command by the backend carries a new one. |
| MQTT | A publish/subscribe messaging protocol for devices. Senders publish to a topic; receivers subscribe to it. |
| NVS | Non-Volatile Storage: the key-value store ESP-IDF provides on flash, used for settings and credentials. |
| OTA | Over-the-air update: replacing the firmware over the network. |
| Owner task | The single task that runs all application logic, so no two commands can interleave. See 3.4. |
| Partition | A fixed region of flash reserved for one purpose, such as the firmware image or the journal. |
| Persistent session | An MQTT setting under which the broker keeps messages for a device while it is offline and delivers them on reconnect. |
| PlatformIO | The build and flashing tool used to compile the firmware and load it onto the board. |
| Port | An interface the application defines for something it needs from outside. In C here, a struct of function pointers. |
| Provisioning token | The one-time secret created in the backend that lets a new device enroll. |
| PUBACK | The broker's acknowledgement that it received a published message. |
| QoS 1 | The MQTT delivery mode "at least once": the sender repeats until acknowledged, so duplicates are possible. |
| Retained message | An MQTT message the broker stores and hands to every new subscriber of its topic. Used for the door-state snapshot. |
| RS485 | An electrical standard for serial communication over a two-wire pair (A and B), common in industrial equipment. Only one device may talk at a time. |
| Sanitizer | A compiler option that makes a test crash at once on memory errors or undefined behaviour instead of continuing silently. |
| Slice | One step of this plan: a pull request that adds one layer of capability with its tests. |
| Snapshot | The MQTT message listing the current door state of every compartment. |
| SNTP | The protocol the device uses to get the current time from the internet. TLS and message timestamps need it. |
| SoftAP | The ESP32 acting as a Wi-Fi access point itself, so a phone can connect to it for setup. |
| Task | The FreeRTOS word for a thread. |
| TLS | The encryption and server-identity check used for the MQTT connection. |
| Topic | The address of an MQTT message, such as `locker/<uuid>/command`. |
| Torn write | A record left half-written because power was lost during the write. |
| Transaction ID | An identifier unique to one user action. It stays the same when the backend resends the command. |
| UART | The chip's serial port hardware. One UART talks to the converter; another carries the USB console. |
| Unity | The C unit-test framework that ships with ESP-IDF. |
| Use case | One operation of the application, such as opening a compartment or applying configuration. |
| Watchdog | A timer that restarts the chip if the firmware stops responding. |
| XY-485 | The small converter board between the ESP32's serial pins and the RS485 pair. |

## 9. References

- Issues #56, #57, #58, #59, #70, #307; PR #312
- ADR-0024, ADR-0040, ADR-0041, ADR-0046, ADR-0048, ADR-0050, ADR-0061, ADR-0063
  (`0063-prioritize-compartment-unlock-actuation.md`)
- `docs/asyncapi/`
- `locker-client/src/` and `locker-client/tests/`
