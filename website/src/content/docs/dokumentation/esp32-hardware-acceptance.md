---
title: Hardware acceptance
description: How to run the ESP32 firmware's bench checks and record the result.
---

A firmware PR merges only when its hardware checks pass. `scripts/hwtest.py` runs them in one session and writes the record that CI checks. `python scripts/hwtest.py list` shows the current status of every check.

The checks are named `HW-A1` in the ADRs and the test catalogue. The tool drops the prefix: `--only A,B3`, and `A1` in its output.

The groups' current state is on [Choose your controller](/dokumentation/esp32/#maturity-of-the-esp32-firmware-bench).

## Before you start

- The [bench](/dokumentation/esp32/parts/): board, converter, panel and one lock on channel 1.
- A flashed, erased unit with no settings. `hwtest.py` needs bench mode, so use a release or development build.
- `pip install -r requirements.txt` in `locker-firmware/`.
- No uncommitted changes in `locker-firmware/`. The tool refuses to run otherwise, because the record names the committed tree.
- Someone at the bench. Checks marked **ACTUATES** release the lock.

## Run the checks

```bash
python scripts/hwtest.py run --port COM3 --image build/locker-firmware.bin
```

`--image` is the `.bin` you flashed, and it must be that one: its SHA-256 goes in the record. The tool drives the USB console and asks you for the physical steps. Before each check that
releases the lock, type `go` or `skip`.

| Option | Use |
| --- | --- |
| `--only A,B3` | Only these groups and checks |
| `--resume` | Only what is still missing, failed or not run |
| `--failed` | Only what failed |
| `--operator "Name"` | Who ran it, instead of Git's identity |
| `--board 1`, `--address 0` | The panel and the channel under test |

`python scripts/hwtest.py list` shows every check with its status. Ctrl+C ends a run, and the checks finished so far stay in the record.

## Rules for a run

- Run the groups in order. A failure makes later results unreliable: stop, fix it, and restart from the failed group.
- Every fix is a commit on the same PR and must pass the [gates](/dokumentation/esp32-development/#run-the-gates).
- A check that was not run is marked as not run, never as passed.
- Only a run against the final commit of the PR counts for the merge.

## The record CI checks

`run` writes `locker-firmware/hardware-record.json` after every check. Commit it. `python scripts/hwtest.py check`, which needs no hardware, fails if:

- the record is missing, or names another tree. The tree hash covers every committed file in `locker-firmware/` except Markdown and the record, so any firmware change needs a new run;
- a check failed, is missing, or was not run without being deferred;
- the operator, the date or the firmware SHA-256 is missing.

The record is the operator's statement, not proof. It only stops a change going in without a run, or with a failed one.

## The checks

**auto** means the tool drives it and you confirm what it cannot see. **manual** means you run it elsewhere and report. **deferred** checks are recorded as not run, with their reason, and do not block the record.

### Group A: Serial bus and panel

| ID | Check | How |
| --- | --- | --- |
| HW-A1 | Power-cycle three times while idle: the unlock counter stays 0 and the lock does not move | auto |
| HW-A2 | `self-test` passes | auto |
| HW-A3 | `query 1` returns a valid frame | auto |
| HW-A4 | **Actuates.** Query with the latch closed, open and closed again, and record the bit and polarity | auto |
| HW-A5 | Panel unpowered: transmit, and nothing comes back | auto |
| HW-A6 | **Actuates.** `unlock 1 0` releases the lock exactly once | auto |
| HW-A7 | Reply times of queries and one unlock are inside the deadline | auto |
| HW-A8 | Panel power removed: the bus disconnects, and recovers without a restart | auto |
| HW-A9 | 200 unlocks and 200 queries: longest reply below the deadline, settle period above it | deferred |

### Group B: Commands and journal

| ID | Check | How |
| --- | --- | --- |
| HW-B1 | **Actuates.** `apply_config`, then one open: the lock releases once and the response is printed | auto |
| HW-B2 | Duplicate open, with the same and a new message ID: the counter does not change | auto |
| HW-B3 | Restart: the mapping is still in effect | auto |
| HW-B4 | **Actuates.** Cut power right after an open, five times: a stored outcome, and no unlock at boot | auto |
| HW-B5 | Power cut at claim, transmit, completion and PUBACK | deferred |
| HW-B6 | 600 commands: the journal compacts without refusing one, the newest 256 replay, and an older one runs as new | deferred |

### Group C: Network, MQTT and enrollment

| ID | Check | How |
| --- | --- | --- |
| HW-C1 | Enroll a fresh unit against the real backend: the bank shows as connected | manual |
| HW-C2 | A broker with a wrong-hostname or self-signed certificate is refused | deferred |
| HW-C3 | `broker_check.py` without `--actuate`: `apply_config` is acknowledged with the matching hash | manual |
| HW-C4 | **Actuates.** One open from the admin panel releases the lock once | manual |
| HW-C5 | **Actuates.** `broker_check.py --actuate`: one open and two duplicates raise the counter by 1 | manual |
| HW-C6 | Access point off for 1 minute, then the broker: it reconnects, and a pending response is delivered | manual |
| HW-C7 | Invalid token: rejected with no retry. After a provisioning reset and a fresh token, it enrolls | manual |
| HW-C8 | Pull power: the backend shows the bank offline | manual |
| HW-C9 | After HW-C6, `diagnostics` shows minimum free heap and owner task stack headroom. Record both | manual |

To run the broker checks:

```bash
BROKER_USER=... BROKER_PASSWORD=... python scripts/broker_check.py \
  --url mqtts://broker.example:8883 --locker-uuid <uuid> --compartments 1:1:0 --feedback door_closing --heartbeat 15
```

Add `--actuate --compartment 1` for HW-C5, and compare `unlock_transmissions` in `diagnostics` before and after. The credentials come from the environment, so they do not show up in a process list.

### Group D: State and door events

| ID | Check | How |
| --- | --- | --- |
| HW-D1 | Door by hand: the admin panel and the app follow within two seconds | manual |
| HW-D2 | **Actuates.** Open through the backend and pull the door: `opened` arrives | manual |
| HW-D3 | **Actuates.** Open through the backend and hold the door: `door_jammed` arrives | manual |
| HW-D4 | Force the door open with no command: an uncommanded-open event arrives | manual |
| HW-D5 | Panel power removed: the heartbeat says disconnected and states go unknown, and both recover | manual |
| HW-D6 | Broker restart with the door open: no door event on reconnect | deferred |

### Group E: USB commissioning

| ID | Check | How |
| --- | --- | --- |
| HW-E1 | **Actuates.** Commission an erased unit with `commission.py` through to an open | manual |
| HW-E2 | Wrong Wi-Fi credentials, corrected over USB without a reset: identity is kept | manual |
| HW-E3 | Move an enrolled unit to another network: identity, mapping and journal are kept | deferred |
| HW-E4 | Unplug USB during a save, ten times: old or new settings, never a mix | deferred |
| HW-E5 | Reset: old credentials fail, and a fresh token enrolls | manual |

### Group F: Commissioning over the setup network

Needs a unit with setup secrets, a laptop running `commission.py --softap` from the same build, and a production build for HW-F6.

| ID | Check | How |
| --- | --- | --- |
| HW-F1 | **Actuates.** Commission an erased unit over the setup network, through to an open from the admin panel | manual |
| HW-F2 | Wrong Wi-Fi password: it retries, the 10 s button hold resets it, and the same unused token enrolls | manual |
| HW-F3 | Enrolled unit: the access point and its port are not reachable | manual |
| HW-F4 | Button: under 10 s nothing, 10 s resets, held at power-up nothing, a stuck jumper nothing | manual |
| HW-F5 | Second station refused, 10-minute window, a wrong setup password fails, and 5 failures close | manual |
| HW-F6 | A production build refuses `configure`, `unlock`, `command` and `reset-network` over USB | manual |
| HW-F7 | A capture shows neither the Wi-Fi password nor the token in clear | manual |
| HW-F8 | A token older than 24 hours is refused, and a fresh one works | manual |

### Group G: Endurance and rollback

| ID | Check | How |
| --- | --- | --- |
| HW-G1 | **Actuates.** 72 hours with an open every ten minutes: no missed response, no reset, stable heap | deferred |
| HW-G2 | Return the bank to the Pi client | deferred |

### Group H: Over-the-air updates

Needs a unit flashed over USB with a signed image, a backend with firmware releases, and a second signed release. Run after groups A to E and G pass.

| ID | Check | How |
| --- | --- | --- |
| HW-H1 | Blank board flashed with the signed image: it enrolls, then HW-A1, HW-A6, HW-C4, HW-D2 and HW-D4 again | manual |
| HW-H2 | Update an idle unit through the backend: it restarts into the new version, and the backend shows it | manual |
| HW-H3 | **Actuates.** An open during an update and one during a rollback's trial window: handled once, never lost | manual |
| HW-H4 | A bad signature and an image signed with another key are refused: the old version keeps running | manual |
| HW-H5 | Truncated image, then Wi-Fi cut mid-download: the old version runs, and the backend shows the failure | manual |
| HW-H6 | A valid image that cannot reach the broker: back to the old version after the trial window | manual |
| HW-H7 | Power cut during the image write, ten times: it always boots a valid image | manual |
| HW-H8 | After an update and a rollback: identity, mapping and journal intact, and no unlock caused | manual |
| HW-H9 | Minimum free heap during a download with MQTT open leaves the HW-C9 stack headroom | manual |
| HW-H10 | The bootloader boots an app built with the next ESP-IDF patch release | manual |
| HW-H11 | Roll back an image on trial after it handled commands: the previous image reads the journal | manual |

HW-H1 says "bootloader with rollback", not signature checks, because the bootloader does not verify signatures at boot with hardware secure boot off. The running app checks them when an update arrives, which HW-H4 tests.

## Why the deferred checks are deferred

| Check | Reason |
| --- | --- |
| HW-A9 | 200 actuations. The bench measured about 265 ms against a 1500 ms deadline and a 3000 ms settle period, and HW-A7 rechecks queries |
| HW-B5 | Needs fault injection. Covered by host tests |
| HW-B6 | Needs a dedicated unit and 600 commands. Compaction and retention are covered by `tests/host/test_journal_retention.c` |
| HW-C2 | Needs a TLS broker with bad certificates, and the bench broker has no TLS. No check covers a verified `mqtts://` connection yet |
| HW-D6 | An edge case |
| HW-E3 | Needs a second network |
| HW-E4 | Ten manual unplugs |
| HW-G1 | A 72-hour run with 432 actuations |
| HW-G2 | Tests the Pi client's provisioning reset, not this firmware |
