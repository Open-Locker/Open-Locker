# ESP32 locker client

An ESP-IDF implementation of the existing Open-Locker MQTT client contract for
the WROOM-32 D1 mini, XY-485 automatic-direction converter, and proprietary
RS485 locker panel. This is an integrated **bench milestone**, with durable
command recovery. It is not qualified for unattended production.

Architecture: backend → verified MQTT TLS → serialized application queue →
sole UART2 worker → panel-timed unlock. The Pi client remains supported.
See ADR-0068 and ADR-0069 in `../docs/adr/`.

## Hardware and build

Use the confirmed wiring in `../hardware/esp32/README.md`: IO17 → TXD,
IO16 → RXD, 3V3 → VCC, GND → converter GND, A/B → panel A/B. The converter
controls direction; there is no DE/RE wire. UART2 is 9600 8N1; USB is 115200.
The panel powers and times the lock pulse. No unlock is sent during startup.
No panel-to-converter ground wire was confirmed in the handoff; consult the
actual panel/converter documentation before changing its bus reference.

Install PlatformIO Core 6.2.0, then from this directory:

```sh
pio run
pio device list
pio run --target upload --upload-port COMx
pio device monitor --port COMx --baud 115200
```

Select the enumerated device, not a remembered COM number. Close competing
monitors before uploading. The PlatformIO 7.1.3 platform pins ESP-IDF 6.1;
managed MQTT and cJSON versions and hashes are committed in `dependencies.lock`.
The pre-build script preserves the bench bootloader linker workaround.

The preserved `hardware/esp32/` project remains an independent manual test;
this firmware does not import that project's console executable.

## USB commissioning

Close serial monitors, keep USB connected, and install the setup tool dependency:

```sh
python -m pip install -r scripts/requirements-setup.txt
python scripts/usb_setup.py --port COMx
```

Use the enumerated port (`/dev/ttyUSB0` is a typical Linux example). The tool
prompts for Wi-Fi SSID/password, a `mqtts://` broker, bootstrap MQTT username and
password, and the backend's one-time token. Passwords and tokens use hidden
terminal input; the tool does not save settings to disk or print raw serial logs.
There is no setup hotspot, browser portal, HTTP listener, or setup password.
Physical USB access authorizes local setup. Use a trusted computer.

Settings are validated and saved before the device acknowledges `saved` and
restarts. The tool waits for enrollment and the issued-credential MQTT connection. Wi-Fi, SNTP and verified TLS
must succeed; certificate/hostname verification is never disabled. The device
subscribes before registration, commits enrollment uncertainty before sending
the token, persists issued credentials, and clears bootstrap/token material.
Then set the bank profile to **RS485 lock board**, establish feedback polarity,
and send `apply_config` through the backend. Opens require valid configuration.

Run the same tool on an enrolled unit to change Wi-Fi/broker settings. It does
not ask for another token or replace issued credentials or compartment mapping.
Use `--status` to inspect enrollment without changing settings. If serial access
is interrupted, inspect status before retrying; the tool never resends a save
automatically. A `pending` flag means registration might have consumed the token.
Reset provisioning in the backend, then run `reset-network` over USB and use a
new token. The tool never resets enrollment automatically.

The USB console retains `setup`, `status`, `reset-network`, `diagnostics`, and
`self-test`. `setup` and `status` return non-secret enrollment flags. Advanced
clients can send `configure {JSON}` with fields `ssid`, `wifi_password`, `broker`,
and, for new enrollment, `bootstrap_user`, `bootstrap_password`, `token`.
Responses start with `@OPENLOCKER ` followed by JSON. Normal logs may interleave.
Input is UTF-8 JSON, newline-delimited, at most 2048 bytes including `configure `.
Malformed/NUL/oversize lines are discarded through newline. Partial input idle
for ten seconds is also discarded through newline. No input is echoed by firmware.
Do not type credentials into a terminal configured to echo or record input.

USB changes run on the application owner task; configuration cannot race an
unlock or enrollment commit. Existing version-1 NVS settings remain readable;
legacy AP/setup fields are reserved and unused. This is bench provisioning:
credentials remain plaintext in flash, and physical USB protection belongs to
field qualification.

## Safety and bounded behavior

- One panel per bus; at most 64 configured compartments. Board addresses 1–31,
  zero-based channels 0–254; wire channel zero/open-all is never generated.
- Frame buffers are 64 bytes. Reception uses an absolute one-second deadline
  and conservative 25 ms quiet interval. Bench measurements must confirm these
  timings. Status coverage never determines physical channel count.
- An unlock result is definitely not sent, acknowledged, or uncertain. No
  unlock is retried automatically. A timeout/partial write returns a hardware
  error but still tracks possible actuation for later sensor observation.
- Door states come from query-all feedback, not the unlock acknowledgement.
  Opening/closing polarity follows backend configuration, with missing bits
  becoming `unknown`. After three failed polls the snapshot reports unknown.
- Polls run after completion with a 500 ms delay. Snapshots are retained and
  change-only; reconnect/configuration force a snapshot. Detection windows use
  the configured heartbeat interval. Sensor events are QoS 1 while connected,
  but are not a durable event outbox across resets (matching the current Pi path).
- MQTT 3.1.1, persistent sessions, QoS 1, stable client ID, verified TLS and
  Last Will preserve existing topics, including the legacy `modbus_connected`
  field. Application output uses fresh IDs and UTC timestamps after SNTP.
- Maximum MQTT payload: 8192 bytes; ID length: 128 bytes; inbound queue: eight
  items; bus queue: four; MQTT outbox: 16 KiB. Invalid fragments, JSON depth over
  16, duplicate keys, decoded NULs, and invalid timestamps are rejected.
  Required MQTT strings exclude control bytes; IDs stay opaque otherwise.
  Queue overflow causes no actuation; the backend may time out and an operator
  must retry. Limits are embedded-client bounds, not changes to shared schemas.
- Raw flash holds an append-only versioned CRC/commit-marker journal. Claims
  atomically carry message ID, transaction ID and action before any side effect.
  Completions precede publication; delivery is committed after PUBACK. Duplicate
  transactions replay stored responses with fresh message IDs.
- At most **64 distinct command transactions** and **128 distinct message IDs**,
  including apply/invalid commands and redeliveries with new IDs, are retained
  in this bench version. No history is pruned using SNTP or an
  untrusted clock. Capacity exhaustion rejects new work, preserving responses.
  There is no automatic erase or compaction. Normal redelivery is safe but can
  consume extra journal slots when delivery state changes.
- Restart converts committed incomplete claims into an unknown-outcome response;
  it never repeats actuation. A partially written or corrupt slot disables
  startup/actuation. This sacrifices automatic recovery for conservative safety;
  interrupted-write recovery/compaction needs production qualification.
- NVS corruption is never handled by erase-and-retry. Credentials
  are plaintext at rest in this bench image. Flash encryption, secure boot,
  authenticated journal rollback protection, and trusted time are deferred.

## Recovery and Pi restoration

If the token was consumed and its reply was lost, the device does not silently
register again. Reset provisioning in the backend first to revoke that identity
and issue a fresh token. On the device run `reset-network` over physical USB;
this clears network/enrollment and runtime mapping, retains the safety journal,
and restarts. Commission with the new token, then apply configuration again.
Do not reuse the prior Pi/ESP credentials or persistent broker session identity.

Returning a bank to Pi follows the same backend reset/new-token workflow. Stop
the ESP, restore the supported Pi image, provision the Pi with fresh credentials,
apply configuration, and verify hardware/profile and state before operation.
Never connect two controllers to the panel concurrently.

Journal corruption/full capacity requires operator investigation and an audited
new provisioning generation before any destructive factory recovery. Do not
erase deduplication history while the prior broker identity remains usable.

## Tests and evidence

```sh
python -m pip install -r tests/requirements.txt
python tests/run_host_tests.py
python tests/analyze_core.py GCC_PATH
python -m pip install -r tests/requirements-hil.txt
python tests/target_smoke.py --port COMx --firmware .pio/build/wemos_d1_mini32/firmware.bin
```

Native tests compile the actual C modules with fake flash, bus, clock inputs and
MQTT. They consume the shared apply-config example, independently verify its
canonical SHA-256 input, cut six journal write boundaries, and validate generated
messages against eight current backend MQTT schemas. Python supplies the hash
oracle; target `self-test` separately checks the PSA SHA-256 adapter's known vector.
Linux CI adds ASan/UBSan and GCC's analyzer, builds the target, and records source
and firmware hashes. Local target smoke requires an already-flashed device and
checks USB status, rejection of empty settings, `self-test` and `diagnostics`,
never lock commands.

`diagnostics` reports free/minimum heap, main task stack headroom, journal usage,
panel health, and UART unlock transmission attempts. `self-test` checks framing
and SHA-256 without touching the panel. Use these before/after fault tests.

For opt-in real-broker tests, create an ignored `hil.local.json`:

```json
{
  "broker": "mqtts://YOUR_BROKER:8883",
  "username": "OPERATOR_IDENTITY",
  "password": "OPERATOR_PASSWORD",
  "locker_uuid": "YOUR_BANK_UUID",
  "feedback_type": "door_closing",
  "compartments": [{"compartment_number": 1, "slaveId": 1, "address": 0}],
  "test_compartment": 1
}
```

The identity must be authorized to publish commands and read this bank's replies.
`python tests/broker_hil.py --settings hil.local.json` tests configuration/hash
interoperability. Add `--allow-actuation` only on an attended bench: it sends one
open plus two duplicate publications. Inspect the physical lock and verify
`unlock_tx` increases once. `HARDWARE_ERROR` remains possible if the ACK is missing;
matching replayed replies alone does not establish physical opening.

Remaining operator gates are listed in `HARDWARE_ACCEPTANCE.md`. Automated builds
do not establish polarity, grounding, relay pulse safety, power-cut behavior on
real flash, revocation, USB setup security, or production reliability.
