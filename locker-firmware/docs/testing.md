# Testing the firmware

How the firmware is tested: the gates every change must pass, the host test
catalogue, and the hardware acceptance checks run on a bench before a
firmware PR merges. Commands are in the [README](../README.md#build-and-test).

## Gates

Two gates run on every push, locally and in CI, with one command:
`python scripts/verify.py`. Neither needs hardware. CI runs it in the
`locker-firmware-ci` workflow, which is a required check, and builds the target
image in a separate step.

| Gate | What runs |
| --- | --- |
| **Static** | Target build with warnings as errors; layer include check; compiler static analysis of the pure components |
| **Host** | Every host suite, built with AddressSanitizer and UndefinedBehaviorSanitizer; schema validation of every emitted MQTT message; the Python tests of the tools; line coverage |
| **IDF host** | The ESP-IDF adapters on ESP-IDF's Linux target, with the SDK below them replaced by CMock mocks generated from its real headers (`tests/idf_host/`). Needs ESP-IDF, like the target build |

Rules:

- Host suites use Unity, built by the host CMake project in `tests/host/`. One
  file per suite, one function per test.
- Host suites compile the real component sources. Only ports are faked. The
  fakes in `tests/host/fakes/` record call order, so ordering rules are
  asserted, not assumed.
- **Encoded messages are checked against the AsyncAPI schemas.** Tests that
  encode a message pass it to `emit_message()`, which appends it to the file
  named by `LC_EMITTED_MESSAGES`. The host gate validates every line of that
  file against `docs/asyncapi/schemas`.
- **Test IDs.** A test listed in the catalogue below carries its ID in its
  name, for example `test_S2_H27_claim_committed_before_bus_call`. `verify.py`
  reads this file and fails when an ID of an implemented area
  (`IMPLEMENTED_SLICES` in `verify.py`) has no test, or a test carries an ID
  this file does not list. Tests for details the catalogue does not list carry
  no ID; most live in the `*_edge` suites.
- Line coverage of `lc_domain`, `lc_application` and the pure adapters
  (`lc_mqtt_codec`, `lc_rs485_lock_board`, `lc_journal`) must be at least 95 %.
  Every component is listed in `verify.py` as pure, header-only or adapter; the
  gate fails on a component in none of them.
- A test that fails intermittently is a failing test. No test is removed or
  skipped without a line in the PR saying why. Changing a listed test needs a
  line in the PR description saying what changed and why.
- New work adds its tests to the catalogue under a new `S<n>` prefix, writes
  them before the code, and extends `IMPLEMENTED_SLICES` when they pass.

## Host test catalogue

The prefix `S1` to `S5` groups the tests by the part of the firmware they
cover; it is the order in which those parts were built.

### Serial bus and the RS485 panel (S1)

Host suite `test_rs485_codec`:

| ID | Test |
| --- | --- |
| S1-H01 | Unlock for board 1, address 0 encodes as `8A 01 01 11 9B` |
| S1-H02 | Query-all for board 1 encodes as `80 01 00 33 B2` |
| S1-H03 | Board addresses 0 and 32 are rejected |
| S1-H04 | Address 255 is rejected; no input produces wire channel zero |
| S1-H05 | Unlock reply status `00`/`11` means succeeded/failed with door-closing feedback, and the reverse with door-opening feedback (ADR-0071) |
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
| S1-H32 | An unlock acknowledgement followed in the same burst by the panel's door event (`82`) is `ACKNOWLEDGED` |
| S1-H33 | A status reply followed in the same burst by a door event is read, and the bus stays connected |
| S1-H34 | An unlock reply with the failure status is `REFUSED`, and the bus stays connected |
| S1-H35 | A miswired bus that only echoes requests never acknowledges an unlock (documents that its status echo reads as all doors open) |

Host suite `test_console_line`:

| ID | Test |
| --- | --- |
| S1-H25 | `unlock` and `query` parse valid arguments and reject out-of-range ones |
| S1-H26 | A line over the length limit is discarded through its newline and nothing is executed |
| S1-H36 | A CR, an LF and a CRLF each end exactly one line (ESP-IDF's monitor sends CR) |

### Commands, journal and MQTT codec (S2)

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
| S2-H21 | A write cut at every byte boundary of a claim leaves no claim, never a usable half record; the journal stays healthy unless the damage cannot be told from corruption |
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

Suites `test_open_compartment`, `test_apply_config`, `test_app_dispatch`:

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
| S2-H50 | Events run strictly one at a time, in arrival order; an `apply_config` arriving behind an open runs only after the open has completed |
| S2-H52 | While an acknowledgement is outstanding, the owner task goes on processing events |
| S2-H53 | An acknowledgement event for an unknown or already delivered response changes nothing |

### Network, MQTT and enrollment (S3)

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

### State and door events (S4)

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

### USB commissioning (S5)

Host suite `test_commission_device`:

| ID | Test |
| --- | --- |
| S5-H01 | Valid settings are saved, then acknowledged, then the restart is requested, in that order |
| S5-H02 | An SSID or Wi-Fi password of invalid length, or a broker URL that is not `mqtts://`, is rejected and nothing is saved (release build) |
| S5-H03 | A store failure produces no acknowledgement and no restart |
| S5-H04 | On an enrolled device, a network change keeps credentials, locker UUID, mapping, and journal (the stored broker URL stays too, S6-H10) |
| S5-H05 | On an enrolled device, token and bootstrap fields in the input are ignored |
| S5-H06 | A device with the pending flag set refuses new settings until it is reset |
| S5-H07 | Reset clears identity, credentials, and mapping, and keeps the journal |
| S5-H08 | When online, the offline message is published before the restart is requested |
| S5-H09 | New commands are refused once the settings are stored, before the restart; a failed save leaves the device taking commands |
| S5-H19 | A development build (`CONFIG_LC_ALLOW_PLAINTEXT_MQTT`) also accepts an `mqtt://` broker URL with a host, and still rejects other schemes (ADR-0069) |

Host suite `test_console_protocol`:

| ID | Test |
| --- | --- |
| S5-H10 | An oversized line is discarded through its newline; its tail is never executed as a command |
| S5-H11 | A line containing NUL or invalid UTF-8 is rejected |
| S5-H12 | A partial line idle past the timeout is discarded |
| S5-H13 | An unknown command gets an error reply and changes nothing |
| S5-H14 | No reply contains any submitted value |
| S5-H15 | Input processed per pass is bounded, so a flood cannot starve the owner task |

Tool suites `test_commission.py` and `test_commission_softap.py` (unittest, run in the host gate):

| ID | Test |
| --- | --- |
| S5-H16 | Secrets are read without echo and never written to disk or to output |
| S5-H17 | A lost serial connection after sending is reported; the tool does not resend |
| S5-H18 | The tool never issues a reset on its own |

### SoftAP provisioning (S6)

Rows are added in the commit that writes each test (ADR-0074). Host suite
`test_commission_device`:

| ID | Test |
| --- | --- |
| S6-H10 | `configure` on an enrolled unit keeps the stored broker URL (S5-H01 to H09 still pass, with S5-H04 changed accordingly) |
| S6-H11 | On an enrolled unit, broker and token fields are ignored and Wi-Fi settings alone are accepted |
| S6-H15 | Factory reset wipes identity, mapping and settings, keeps the journal, publishes nothing. A failed later clear returns the "partial" result |

Host suite `test_boot_mode`:

| ID | Test |
| --- | --- |
| S6-H05 | The setup network starts when no settings are stored |
| S6-H06 | The setup network never starts when settings are stored or the unit is enrolled, whatever else is true |
| S6-H24 | Boot with an empty identity and settings present finishes the wipe (settings and mapping), then decides the mode |
| S6-H26 | Boot with an empty identity, a mapping and no settings leaves the mapping alone (bench mode, HW-B3) |

Host suite `test_button`:

| ID | Test |
| --- | --- |
| S6-H16 | A press under 10 s does nothing |
| S6-H17 | A press from 10 s to under 30 s resets, once, on release |
| S6-H18 | A press of 30 s or more is void |
| S6-H19 | A button held at power-up does nothing until it has been released for a second |
| S6-H20 | A stuck pin never arms |
| S6-H21 | A gap between samples voids the press and the arming |
| S6-H22 | A floating pin reads released (pull-up) and does nothing |
| S6-H23 | Bounce under 50 ms is ignored |
| S6-H25 | A reset the owner task refused is offered again on the next samples for 2 s, then reported once |

Host suite `test_softap_portal`, and the tool suite `test_verify.py` for the
layer rule:

| ID | Test |
| --- | --- |
| S6-H03 | A failed Security 2 session reaches no endpoint, and 5 failures close the window |
| S6-H07 | The portal closes after saving and after 10 minutes. A unit with no settings reopens it on the next boot |
| S6-H08 | Nothing reopens a closed portal during the run, so a Wi-Fi failure after commissioning never does (the token is kept by S5, `commission_device`) |
| S6-H09 | The portal never exposes status, diagnostics or stored settings: only the handshake, the Wi-Fi endpoint and `ol-config` exist, the last two behind an established session |
| S6-H12 | No code path from the portal reaches the bus port (enforced by the layer rule in `verify.py`) |
| S6-H13 | A second station is refused while one is connected |

Host suite `test_ol_config` (the collector also has a fixed-seed random test and
a libFuzzer target in `tests/fuzz/`, built by hand with clang):

| ID | Test |
| --- | --- |
| S6-H01 | A valid `ol-config` submission reaches `commission_device` with the same values as a USB one |
| S6-H02 | An oversized payload, a missing field or a malformed encoding is rejected and nothing is saved |
| S6-H04 | No response contains a submitted value: the reply is one status byte, the same for every refusal |

Host suite `test_console_protocol`:

| ID | Test |
| --- | --- |
| S6-H14 | A production build has no `configure`, `unlock`, `command` or `reset-network`: the console answers them like any unknown word |

### Over-the-air updates (S7)

Not implemented as a whole yet: `IMPLEMENTED_SLICES` gains 7 with the last
slice. IDs of this area whose test exists are already checked against this
list. The decisions are in ADR-0075 and ADR-0076. S7-H14 (S1 to S5 pass
unchanged) is the existing suites staying green, not a test of its own.

Host suite `test_mqtt_codec`:

| ID | Test |
| --- | --- |
| S7-H05 | A malformed `update_firmware` command, a non-HTTPS URL and a URL over 256 characters are `UPDATE_INVALID` |
| S7-H13 | The `update_firmware` responses, the `firmware_update_failed` event and the firmware heartbeat fields validate against their schemas |

Host suite `test_firmware_update`, through `lc_app_handle_command`, `lc_app_dispatch` and
`lc_app_tick` with a fake update port:

| ID | Test |
| --- | --- |
| S7-H01 | An update command reaches the update port with the URL and version from the command |
| S7-H02 | An update is refused (`UPDATE_BUSY`) while an unlock's door is still being watched, and a new one starts once the unit is idle |
| S7-H03 | An update is `UPDATE_NOT_READY` on an unhealthy journal, a bus that is not connected and an image on trial |
| S7-H04 | The running version, a downgrade without the flag and the last version rolled back are refused |
| S7-H06 | A failed download or verification is reported once as the `firmware_update_failed` event, with no second response, and the next slot stays unselected |
| S7-H07 | The next slot is selected only after the image is verified |
| S7-H11 | A repeated command with the same transaction ID does not download twice; the stored response is sent again |

## Hardware acceptance

Everything that needs the real board, converter, panel, lock, Wi-Fi or broker
is checked on the bench described in the [README](../README.md#hardware). A
firmware PR merges only when its checks pass.
`scripts/hwtest.py` runs the checks in one session and writes the record that
CI checks.
`hwtest.py list` shows every check with its status in the record, and
`hwtest.py run --resume` runs only what is still missing, failed or not run;
`--failed` reruns only the checks that failed.

Rules:

- Run the groups in order. A failure in an earlier group makes later results
  unreliable; stop, fix, and restart from the failed group.
- Every fix is a commit on the same PR and must pass the gates. Where the fault
  can be reproduced without hardware, the commit adds a host test for it.
- Record each check with the source commit, the firmware SHA-256, the operator
  and the date. A check that was not run is marked as not run, never as passed.
- Checks marked **actuates** release the lock and need someone at the bench.
- Only a run against the final commit of the PR counts for the merge.

### Group A: Serial bus and panel

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

### Group B: Commands and journal

| ID | Check |
| --- | --- |
| HW-B1 | **Actuates.** Feed `apply_config` then one open over the console: the lock releases once and the response is printed |
| HW-B2 | Feed the same open again, once with the same message ID and once with a new one: the unlock transmission counter does not change |
| HW-B3 | Restart with configuration applied: the mapping is still in effect |
| HW-B4 | **Actuates.** Cut ESP32 power immediately after feeding an open, five times: after each restart the transaction has a stored outcome and no unlock is sent during boot |
| HW-B5 | **Actuates.** Cut ESP32 power at claim, after transmission, at completion, and before PUBACK: each case ends in a stored outcome and no second unlock |
| HW-B6 | Send 600 commands on a dedicated test unit: the journal compacts at least once without refusing a command, the newest 256 delivered transactions are still replayed, and an older one is executed as new |

### Group C: Network, MQTT, and enrollment

| ID | Check |
| --- | --- |
| HW-C1 | Enroll a fresh unit against the real backend; the locker bank shows as connected |
| HW-C2 | Point the device at a broker with a certificate for another hostname, then at a self-signed one: it refuses both |
| HW-C3 | `broker_check.py` without the actuation flag: `apply_config` is acknowledged with the matching hash |
| HW-C4 | **Actuates.** One open from the admin panel releases the lock once |
| HW-C5 | **Actuates.** `broker_check.py` with the actuation flag: one open and two duplicates raise the unlock transmission counter by one |
| HW-C6 | Switch the access point off for one minute, then the broker for one minute: the device reconnects unaided and a response pending at the time is delivered |
| HW-C7 | Use an invalid token: the device reports rejection and does not retry; after a backend provisioning reset and a fresh token it enrolls |
| HW-C8 | Pull power from the ESP32: the broker publishes the Last Will, and the backend shows the bank offline once the heartbeat times out (it does not act on the Last Will yet, #43) |
| HW-C9 | Record minimum free heap and owner task stack headroom after HW-C6 (`diagnostics` reports both) |

### Group D: State and door events

| ID | Check |
| --- | --- |
| HW-D1 | Open and close the door by hand: the admin panel and the mobile app follow within two seconds |
| HW-D2 | **Actuates.** Open through the backend and pull the door: an `opened` event arrives |
| HW-D3 | **Actuates.** Open through the backend and hold the door shut: `door_jammed` arrives after the window |
| HW-D4 | Force the door open by hand with no command: an uncommanded-open event arrives |
| HW-D5 | Remove panel power: the heartbeat reports the bus as disconnected and states become `unknown`; restore it and both recover |
| HW-D6 | Restart the broker with the door open: no door event is published on reconnect |

### Group E: USB commissioning

| ID | Check |
| --- | --- |
| HW-E1 | **Actuates.** Commission a fully erased unit with `commission.py` through to an open from the admin panel |
| HW-E2 | Enter wrong Wi-Fi credentials, then correct them over USB without a reset: identity is kept |
| HW-E3 | Move an enrolled unit to another Wi-Fi network: identity, mapping, and journal are kept |
| HW-E4 | Unplug USB during a save, ten times: the device has either the old or the new settings, never a mix |
| HW-E5 | Reset the unit: the old credentials no longer work, and a fresh token enrolls it |

### Group F: SoftAP commissioning

Needs a unit with its setup secrets in NVS (`scripts/device_secrets.py --nvs-csv`),
a laptop running `scripts/commission.py --softap` from the same build, and a
production-configured build for HW-F6. Run against a backend with the
token-expiry change deployed for HW-F8 (ADR-0074). None has run against the
final commit yet. The bench spike of 2026-10-21 (ADR-0074, open points) already
went through the HW-F1 path and the HW-F7 capture on a development build.

| ID | Check |
| --- | --- |
| HW-F1 | **Actuates.** Commission an erased unit from a laptop with no cable, through to an open from the admin panel. Success is read in the panel |
| HW-F2 | Wrong Wi-Fi password: the unit keeps retrying, the 10 s button hold resets it, and within 24 h of issue the same unused token enrolls it. Checked in the panel |
| HW-F3 | On an enrolled unit, scan for the access point and probe the port: neither is reachable |
| HW-F4 | Button: under 10 s does nothing, 10 s resets, held at power-up does nothing, a jumper stuck down does nothing |
| HW-F5 | A second station is refused. The window closes after 10 minutes. A wrong setup password fails the session, and 5 failures close the window |
| HW-F6 | A production build refuses `configure`, `unlock`, `command` and `reset-network` over USB |
| HW-F7 | A traffic capture shows neither the Wi-Fi password nor the token in clear, including on `ol-config` |
| HW-F8 | Against a backend with the token-expiry change deployed: a token older than 24 hours is refused, and a fresh one works |

### Group G: Endurance and rollback

| ID | Check |
| --- | --- |
| HW-G1 | **Actuates.** Run for 72 hours with an open every ten minutes: no missed response, no reset, and minimum free heap is stable between the first and last hour |
| HW-G2 | Return the bank to the Pi client with a provisioning reset and fresh credentials, with the ESP32 disconnected from the panel |
