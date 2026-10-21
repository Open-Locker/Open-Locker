# Firmware architecture

How `locker-firmware/` is built: its layers and components, the ports between
them, the task model, the command journal, the safety rules the code is held
to, and how the decisions of
[ADR-0069](../../docs/adr/0069-esp32-locker-firmware.md) are implemented. For wiring,
building and commissioning, see the [README](../README.md). For tests, see
[testing.md](testing.md).

The firmware does for one RS485 lock panel what the Raspberry Pi
`locker-client/` does. It speaks the same MQTT contract
([`docs/asyncapi/`](../../docs/asyncapi/mqtt.yaml)), so the backend cannot tell
the two apart. What every client must do on the wire is listed in
[Locker command scenarios](../../docs/locker-command-scenarios.md). The
firmware shares the MQTT schemas and the panel's golden frames with the Pi
client, but no code.

## Layers and components

The firmware follows the Pi client's hexagonal layout (ADR-0024). ESP-IDF
components are the unit that enforces dependencies, through `REQUIRES` in each
`CMakeLists.txt`: one component per layer, one per adapter.

```text
locker-firmware/
  components/
    lc_domain/            pure C11: compartments, door detection, config, errors
    lc_ports/             header-only port structs
    lc_application/       use cases; requires lc_domain and lc_ports only
    lc_mqtt_codec/        JSON <-> domain values, envelope, fragment assembly (pure C + cJSON)
    lc_mqtt_espidf/       esp-mqtt transport; implements the outbound and provisioning ports
    lc_rs485_lock_board/  frame codec and bus adapter; implements the locker bus port
    lc_serial_uart/       UART transaction transport for the bus adapter
    lc_journal/           command journal over a flash I/O struct (ADR-0070)
    lc_storage_nvs/       settings, identity and runtime config stores; journal partition I/O
    lc_net_wifi/          Wi-Fi station and SNTP
    lc_button/            reset gesture state machine (pure C, ADR-0074)
    lc_softap_portal/     setup network decisions: window, one station, endpoints (pure C, ADR-0074)
    lc_usb_console/       driving adapter: serial line protocol to the commissioning use case
  main/                   composition root: app_main.c wires adapters to ports; owner_task.c
  tests/host/             host build: fakes and one suite per use case and pure adapter
  tests/idf_host/         ESP-IDF Linux target: SDK adapters against CMock mocks of the SDK
  scripts/                verify gate, commissioning, broker check, hardware acceptance
```

Allowed dependencies, checked by `verify.py`:

```text
lc_domain        <- nothing
lc_ports         <- lc_domain
lc_application   <- lc_domain, lc_ports
adapters         <- lc_domain, lc_ports, their own SDK
main             <- everything
```

An adapter never requires another adapter. Where two must cooperate, the
composition root passes one into the other through a small struct, as the bus
adapter gets its serial transport. That seam is also what lets the bus adapter
run on a host against a scripted serial fake. The pure components (`lc_domain`,
`lc_application`, `lc_mqtt_codec`, `lc_rs485_lock_board`, `lc_journal`, `lc_button`, `lc_softap_portal`) include
no SDK or RTOS header and build on a host unchanged.

### Mapping from the Pi client

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

One deliberate difference: the command guard lives in the application layer.
On the ESP32 the claim must be durable before any side effect and the journal is
the dedup store, so the ordering rule is application policy, not MQTT plumbing.
The commissioning use case (`commission_device.c`) has no Pi counterpart.

## Ports

Ports are structs of function pointers with a context pointer, one struct per
responsibility. The two that carry the most policy:

```c
/* lc_ports/locker_bus_port.h: protocol-neutral */
typedef struct {
    void *ctx;
    bool (*apply_profile)(void *ctx, const lc_hardware_profile_t *profile);
    /* Never retried by the adapter. */
    lc_unlock_observation_t (*unlock_compartment)(void *ctx, lc_target_t target);
    bool (*read_compartment_states)(void *ctx, uint8_t board, const uint8_t *addresses,
                                    size_t count, lc_door_t *out);
    lc_bus_state_t (*connection_state)(void *ctx);
} lc_locker_bus_port_t;
```

An unlock ends as acknowledged, not sent, or outcome unknown. Wire protocol,
channel numbering and feedback polarity stay in the adapter; the application
only ever sees `open`, `closed` or `unknown`.

```c
/* lc_ports/outbound_port.h: typed messages, no topics, no JSON */
typedef struct {
    void *ctx;
    lc_publish_id_t (*command_response)(void *ctx, const lc_command_result_t *result);
    lc_publish_id_t (*heartbeat)(void *ctx, uint32_t uptime_seconds, bool bus_connected);
    lc_publish_id_t (*compartment_snapshot)(void *ctx, const lc_compartment_state_t *states,
                                            size_t count);
    lc_publish_id_t (*open_detection)(void *ctx, const lc_open_detection_t *event);
    lc_publish_id_t (*uncommanded_open)(void *ctx, const lc_uncommanded_open_t *event);
    lc_publish_id_t (*connection_offline)(void *ctx, lc_offline_reason_t reason);
} lc_outbound_port_t;
```

Outbound calls never wait for the broker. A call returns a publish ID when the
MQTT adapter accepted the message, or `LC_PUBLISH_REFUSED`. The broker's
acknowledgement (PUBACK), a failure or a timeout arrive later as owner-queue
events carrying that ID. Only the acknowledgement marks a command response as
delivered in the journal, so "delivered" means the broker has it, and the owner
task keeps polling doors in the meantime.

The other ports are `command_journal_port.h` (find, seen, claim, complete,
delivered, and the open transactions), `store_ports.h` (settings, identity,
runtime config), `provisioning_gateway_port.h` (subscribe to the reply, send
the registration), `clock_port.h` (monotonic milliseconds; wall time stays
inside the MQTT adapter), `hasher_port.h` (SHA-256) and `system_port.h`
(restart).

## Task model

- One **owner task** runs every use case. Driving adapters never call a use
  case directly; they post to the owner task's bounded queue (MQTT message,
  connected, subscribed, publish acknowledged, publish failed, console line,
  tick). This
  is why `apply_config` cannot race an unlock, and it is the only concurrency
  rule the application relies on.
- One **bus worker** behind the locker bus port owns the UART.
- The esp-mqtt task belongs to `lc_mqtt_espidf` and only copies and enqueues.

The owner task never waits for the network. The only thing it waits for is a
reply from the panel, bounded by the bus deadline, and during that wait nothing
else could use the bus anyway. On idle ticks it also does the journal's
background work, one sector erase or one compaction at a time.

Every queue and buffer has a fixed capacity and a stated overflow policy.
Overflow never actuates: a dropped command gets no unlock, and the backend
times out.

## Command journal

The journal replaces the Pi client's dedup file. It lives in its own flash
partition (ADR-0069), split into two regions, and records every command:

- a **claim** (transaction ID, message ID, action), committed before any side
  effect;
- a **completion** with the typed result, committed before the response is
  published;
- a **delivered** record, committed when the PUBACK for that response arrives.

A torn last record counts as never written; any other damage stops all
actuation until the partition is erased. Open transactions are never forgotten,
and the newest 256 delivered ones are kept across compaction. The record
format, retention and compaction are decided in
[ADR-0070](../../docs/adr/0070-esp32-command-journal-record-format-and-retention.md).

## Safety rules

Each rule follows from an accepted decision (ADR-0040, ADR-0041, ADR-0046,
ADR-0048, ADR-0050, ADR-0061) or from ADR-0069, ADR-0070 and ADR-0071. Each is held by
the host tests with the given prefix in [testing.md](testing.md).

| Rule | Tests |
| --- | --- |
| An unlock is never retried; the outcome is acknowledged, not sent, or unknown | S1 |
| A timeout or partial write after transmission still counts as a possible actuation for door detection | S1, S4 |
| No unlock frame is sent at startup or on reconnect; wire channel zero (open all) is never generated | S1 |
| After a request times out, the bus is kept silent for a settle period before the next request, so a late reply is discarded and not credited to the next request; where a reply cannot be attributed with certainty the outcome is unknown | S1 |
| The claim is durable before the unlock is sent; the result is durable before it is published; delivery is recorded only after PUBACK | S2 |
| A repeated transaction ID replays the stored response with a fresh message ID and sends nothing to the panel | S2 |
| A claim left incomplete by a restart becomes an unknown-outcome response, never a second unlock | S2 |
| A corrupt journal record disables actuation; a torn last record counts as never written (ADR-0070) | S2 |
| Running out of memory after transmission still leaves a durable final outcome | S2 |
| Journal capacity exhaustion refuses new work and keeps stored responses | S2 |
| `apply_config` cannot run during an in-flight unlock | S2 |
| The registration subscription is confirmed before the one-time token is published | S3 |
| A pending-enrollment flag is persisted before the token is published | S3 |
| A lost or rejected enrollment reply never triggers a second registration | S3 |
| Bootstrap credentials and token are cleared once issued credentials are saved | S3 |
| TLS certificate and hostname verification cannot be disabled; plain `mqtt://` exists only in development builds (ADR-0069) | S3 |
| Missing status bits become `unknown`; repeated failed polls report `unknown` | S4 |
| Reconnect and `apply_config` force a snapshot without false door events | S4 |
| Malformed, oversized, NUL, or stalled commissioning input never saves partial settings | S5 |
| A network change on an enrolled device keeps identity, mapping, and journal | S5 |

## Design decisions

The decisions are in ADR-0069 (firmware), ADR-0070 (journal) and ADR-0071
(panel replies). This section records how they are implemented, and the
smaller choices that have no ADR.

- **Hardware scope.** The RS485 lock panel (`adapter_type = rs485_lock_board`,
  ADR-0061) is the only supported locker protocol, with one panel per bus. Any
  other `adapter_type` and a second board address are rejected. Development
  runs on an ESP32 D1 mini with an XY-485 automatic-direction converter; no
  DE/RE pin is driven. The board selection of ADR-0005 is not used yet.
- **Port naming.** The locker bus port is protocol-neutral: unlock a
  compartment, read compartment states, board address.
- **Tooling in Python.** ESP-IDF is driven by Python (`idf.py`), so the
  project's tools are Python too: `verify.py`, `commission.py`,
  `broker_check.py` and `hwtest.py`.

### Flash layout

`partitions.csv`, with `CONFIG_PARTITION_TABLE_OFFSET=0xC000` in
`sdkconfig.defaults`:

```csv
# Name,    Type, SubType,  Offset,   Size,     Flags
nvs,       data, nvs,      0xD000,   0x6000,
otadata,   data, ota,      0x13000,  0x2000,
phy_init,  data, phy,      0x15000,  0x1000,
nvs_keys,  data, nvs_keys, 0x16000,  0x1000,   encrypted
ota_0,     app,  ota_0,    0x20000,  0x1D0000,
ota_1,     app,  ota_1,    0x1F0000, 0x1D0000,
journal,   data, 0x40,     0x3C0000, 0x40000,
```

- The bootloader gets `0x1000` to `0xC000` (44 KB) instead of about 2.7 KB of
  headroom below the default `0x8000`; rollback, signature checks and secure
  boot make it larger.
- With empty `otadata` the bootloader boots `ota_0`, so `idf.py flash` works
  as with a single app and the first OTA writes `ota_1`.
- Each app slot is 0x1D0000 (1.8 MB). The default build measured 0xfe540
  bytes on ESP-IDF 6.1, leaving 45 % of the slot free. The 36 KB from
  `0x17000` to `0x20000` are unused.
- `nvs_keys` is reserved and unused until flash and NVS encryption are turned
  on (a release blocker, ADR-0074 decision 18). Whether its `encrypted` flag
  behaves as expected is verified only then.
- The journal is found by its label (`lc_storage_nvs/partition_flash.c`), not
  its offset, and is never flash-encrypted (ADR-0070). The table ends exactly
  at 4 MB.
- Rollback, image signing and the minimum chip revision belong to OTA (#59).

### Toolchain

- ESP-IDF `v6.1` exactly, in `idf.py`, CI, the Docker image
  (`espressif/idf:v6.1`) and the README. Bench boards are flashed in full
  (`idf.py flash -a`), which also writes the bootloader.
- The component manager is on and `dependencies.lock` is committed.
  Components outside ESP-IDF come from it: `espressif/mqtt`, `espressif/cjson`,
  and `espressif/network_provisioning` once SoftAP lands (#331). The laptop's
  `esp_prov` must come from the same release.
- The IDF host tests mock `espressif/mqtt` from the headers of the resolved
  copy in `managed_components/`, so the firmware build runs before them.
- Language: C. Rust was considered and not chosen (ADR-0069).

### Release and development builds

- `CONFIG_LC_ALLOW_PLAINTEXT_MQTT` (component `lc_mqtt_espidf`) is `default n`.
  `sdkconfig.dev` turns it on; developers build with
  `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.dev" build`. A
  plain `idf.py build` is the release configuration. Switching needs a deleted
  `sdkconfig`, or the old values stay.
- Commissioning (`lc_application/commission_device.c`) accepts `mqtt://` only
  when the composition root calls `lc_commission_allow_plaintext_broker`, which
  `main/` does only under the option. The pure component never reads
  `CONFIG_*`, so both behaviours are host-tested (S5-H02, S5-H19).
- The transport (`lc_mqtt_espidf/transport.c`) refuses an `mqtt://` URI when
  the option is off. This also covers a URL stored in NVS by a development
  build before a release was flashed over it. With the option on it logs a
  warning on every connect.
- `ESP_TLS_INSECURE` and `ESP_TLS_SKIP_SERVER_CERT_VERIFY` are a build error in
  every configuration.
- CI builds the default configuration and fails if
  `CONFIG_LC_ALLOW_PLAINTEXT_MQTT=y` ends up in its `sdkconfig`.

### Commissioning

Over USB serial with `scripts/commission.py --usb` on bench and development
builds. The SoftAP setup network is the second driving adapter over the same use
case (#57, [ADR-0074](../../docs/adr/0074-esp32-softap-provisioning.md)), used with
`scripts/commission.py --softap`: it opens only on a unit without settings, for 10
minutes, and is re-entered by a button held for 10 s. Its decisions are the pure
`lc_softap_portal` and `lc_button` components; the radio adapter is
`lc_softap_espidf`. Flash and NVS encryption are not on yet (ADR-0074 decision 18).

## Risks

- **RAM.** The journal index, the MQTT reassembly buffer and TLS share a few
  hundred kilobytes. The measured values come from HW-C9 and HW-G1.
- **Late replies on the bus.** The panel protocol has no request identifier, so
  a reply that arrives after its request timed out cannot be told from the
  reply to the next request. The settle period (S1-H27 to S1-H31) covers this
  only if it is longer than the panel's worst reply time, measured in HW-A9. A
  reply later than that can still be misattributed; on the same channel that
  means a wrong report, not a wrong door.
- **Flash behaviour under power loss.** Host tests cut journal writes and
  erases at every byte, with writes cut both in order and out of order. Real
  flash can still fail differently; HW-B4 and HW-B5 check it on the device.
- **Drift from the Pi client.** Two implementations of one contract. The
  shared schemas, the shared golden frames and the ported test names are the
  controls.
- **Unverified hardware facts.** None of these is confirmed: the panel's supply
  voltage and exact model, its channel count, the lock model, the board's flash
  size, the serial parameters and DIP address, and the feedback polarity. The
  build defaults stay assumptions until recorded on the bench; HW-A4 captures
  the polarity.
- **Plaintext credentials.** Wi-Fi and MQTT credentials, and the setup
  password, sit unencrypted in NVS until flash and NVS encryption are turned on
  (ADR-0074 decision 18). Until then the firmware is for an attended bench only.

## Glossary

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
| ESP-IDF | The official software development kit for the ESP32 from its maker, Espressif. Its `idf.py` tool compiles the firmware and loads it onto the board. |
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
| Owner queue | The fixed-size FreeRTOS queue in front of the owner task. |
| Journal | The log in flash that records every command before and after it is acted on. See [Command journal](#command-journal). |
| Kconfig | The build-time configuration system of ESP-IDF, used here for pins and serial settings. |
| Last Will | A message a device registers with the broker in advance. The broker publishes it if the device disappears without saying goodbye. |
| Message ID | An identifier unique to one transmission of a message. A resend of the same command by the backend carries a new one. |
| MQTT | A publish/subscribe messaging protocol for devices. Senders publish to a topic; receivers subscribe to it. |
| NVS | Non-Volatile Storage: the key-value store ESP-IDF provides on flash, used for settings and credentials. |
| OTA | Over-the-air update: replacing the firmware over the network. |
| Owner task | The single task that runs all application logic, so no two commands can interleave. See [Task model](#task-model). |
| Partition | A fixed region of flash reserved for one purpose, such as the firmware image or the journal. |
| Persistent session | An MQTT setting under which the broker keeps messages for a device while it is offline and delivers them on reconnect. |
| Port | An interface the application defines for something it needs from outside. In C here, a struct of function pointers. |
| Provisioning token | The one-time secret created in the backend that lets a new device enroll. |
| PUBACK | The broker's acknowledgement that it received a published message. |
| QoS 1 | The MQTT delivery mode "at least once": the sender repeats until acknowledged, so duplicates are possible. |
| Retained message | An MQTT message the broker stores and hands to every new subscriber of its topic. Used for the door-state snapshot. |
| RS485 | An electrical standard for serial communication over a two-wire pair (A and B), common in industrial equipment. Only one device may talk at a time. |
| Sanitizer | A compiler option that makes a test crash at once on memory errors or undefined behaviour instead of continuing silently. |
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
