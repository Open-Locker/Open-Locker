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

`--image` is the `.bin` you flashed, and it must be that one: its SHA-256 goes in the record. If you flashed a signed image, pass `build/locker-firmware-signed.bin`. The tool drives the USB console and asks you for the physical steps. Before each check that
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

**auto** means the tool drives it and you confirm what it cannot see. **manual** means you run it elsewhere and report. **deferred** checks are recorded as not run, with their reason, and do not block the record. The steps for each manual check are under [Run the manual checks step by step](#run-the-manual-checks-step-by-step).

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
| HW-C9 | Right after HW-C6, with no restart between them, `diagnostics` shows minimum free heap and owner task stack headroom. Record both | manual |
| HW-C7 | Invalid token: rejected with no retry. After a provisioning reset and a fresh token, it enrolls | manual |
| HW-C8 | Pull power: the backend shows the bank offline | manual |

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
| HW-E1 | **Actuates.** Commission a unit with no settings with `commission.py` through to an open | manual |
| HW-E2 | Wrong Wi-Fi credentials, corrected over USB without a reset: identity is kept | manual |
| HW-E3 | Move an enrolled unit to another network: identity, mapping and journal are kept | deferred |
| HW-E4 | Unplug USB during a save, ten times: old or new settings, never a mix | deferred |
| HW-E5 | Reset: old credentials fail, and a fresh token enrolls | manual |

### Group F: Commissioning over the setup network

Needs a unit with setup secrets, a laptop running `commission.py --softap` from the same build, and a production build for HW-F6.

| ID | Check | How |
| --- | --- | --- |
| HW-F1 | **Actuates.** Commission a unit with no settings over the setup network, through to an open from the admin panel | manual |
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

:::note[Planned]
Over-the-air updates are not implemented yet. They come with [#336](https://github.com/Open-Locker/Open-Locker/pull/336), once the image
signing design is settled. This section describes the plan.
:::

Needs a unit flashed over USB with a signed image, a backend with firmware releases, and a second signed release. Run after groups A to E and G pass.

| ID | Check | How |
| --- | --- | --- |
| HW-H1 | The whole run started on a blank board flashed with the signed image: confirm it, with no repeat run | manual |
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

## Run the manual checks step by step

When `hwtest.py` prints `(do it with the backend and broker, then report)`, leave it at its prompt and follow the steps below in a second terminal. Then choose **Pass** or **Fail** and write what you saw, with times and numbers, as the note.

Groups F and H have not been fully run on a bench yet, so these steps come from the firmware and backend code. If a step does not match what you see, say so in the note.

### Prepare for the manual checks

Run firmware commands in `locker-firmware/` and backend commands in `locker-backend/`. The examples assume the local Docker backend, whose log is `storage/logs/laravel.log`. Replace `COM3` with your port, such as `/dev/ttyACM0`, and `<uuid>` with the ID in the address of the bank's edit page. If your Docker stack has its own project name, add `-p <name>` to the `docker compose` commands.

Groups A and B leave the unit in bench mode, but group C onward needs a commissioned unit. An enrolled unit refuses `unlock` and `command`.

**Open the console.** Keep `hwtest.py` running while you do. It does not read the port at a prompt, and keeping the port open avoids restarting the unit when the console opens. What you type is not echoed. Press Enter after each command and `Ctrl+]` to quit.

```bash
python -m serial.tools.miniterm --dtr 0 --rts 0 COM3 115200
```

**Check that the unit did not restart.** `uptime_seconds` should keep rising:

```bash
grep -o 'uptime_seconds[^,]*' storage/logs/laravel.log | tail -3
```

**Wait for online.** A unit can take about a minute from enrolling to **online** in the admin panel. Wait for it before you run a broker check.

**One action can answer several checks.** `hwtest.py` then lists them together, asks once and records the answer for all of them. If only one of them failed, choose **Fail** and name it in the note. It asks in this order, which differs from the tables above:

| Step | Checks |
| --- | --- |
| Bench mode | Groups A and B |
| Commission and open | HW-C1, HW-C4, HW-D2, HW-E1 |
| Broker commands | HW-C3, HW-C5 |
| Outages | HW-C6, then HW-C9, then HW-D5 |
| Tokens | HW-C7, HW-E5 |
| Power | HW-C8 |
| Door | HW-D1, HW-D4, then HW-D3 |
| Wi-Fi over USB | HW-E2 |
| Setup network | HW-F8, HW-F1, HW-F7, then HW-F4, HW-F2, HW-F3, then HW-F5, then HW-F6 |
| Updates | HW-H1, then HW-H2, HW-H9, then HW-H6, HW-H8, then HW-H3, HW-H11, then HW-H4, HW-H5, HW-H7, HW-H10 |

### Group C step by step

#### HW-C1, HW-C4, HW-D2 and HW-E1: Commission a unit and open it

`hwtest.py` asks once and records the answer for all four. This releases the lock, so stay at the cabinet.

1. If the unit has settings, run `reset-network` in the console. This keeps the journal, which is fine: the check needs a unit with no settings, not a full erase.
2. In the admin panel, choose **Restart provisioning** on the bank and copy the token.
3. Commission the unit [over USB](/dokumentation/esp32/commissioning/#over-usb-release-and-development-builds).
4. Open **Locker banks**, and wait for **Status** to show online. This takes about a minute.
5. Run `diagnostics` in the console and note `unlock_transmissions`.
6. Edit the bank, and choose **Open** on compartment 1.
7. Pull the door open.
8. Run `diagnostics` again, and look for the door event:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Pass:** the bank goes online, the lock releases once, the counter rises by 1, the log shows `compartment_open_detected`, and the **Audit Log** shows **Door opened**.

#### HW-C3 and HW-C5: Check commands through the broker

One run of the broker check answers both, and `hwtest.py` asks once. It sends the unit a configuration and checks that the unit stored the same one, then sends one open and two duplicates. It replaces the unit's mapping and heartbeat interval, so `--compartments` must match your wiring. This releases the lock, so stay at the cabinet.

1. Run `diagnostics` in the console and note `unlock_transmissions`.
2. Run the [broker check](#group-c-network-mqtt-and-enrollment) with `--actuate --compartment 1`. For the local backend, `BROKER_USER` and `BROKER_PASSWORD` are `MQTT_USERNAME` and `MQTT_PASSWORD` from `locker-backend/.env`, and the URL is `mqtt://<your PC's address>:1883`.
3. Run `diagnostics` again.

**Pass:** the output shows `PASS apply_config acknowledged with the matching hash`, `the duplicates produced 1 response(s)` and `PASSED`, the lock moves once, and the counter rises by 1.

| Output | Meaning |
| --- | --- |
| `FAIL apply_config: no response` | The unit is offline, or the UUID is wrong. If the bank is online and the UUID is right, it is a fail |
| `FAIL apply_config: <error code>` | The unit refused the configuration. Fail, and note the code |
| `FAIL apply_config: the applied hash differs...` | The unit stored something else. Fail |
| `FAIL could not connect to the broker` | The login or URL is wrong. Fix it and run again |

#### HW-C6: Recover from network outages

This cuts the access point and then the broker. The unit must recover by itself and still deliver a response it could not send. Do not restart or unplug the ESP32, because HW-C9 reads values counted since boot.

To leave a response pending, take away the panel's power: an unlock then takes a few seconds to fail instead of finishing almost at once, and the lock cannot move.

1. Check that the bank shows online.
2. Switch off the panel's power, not the ESP32's. Watch **Hardware** and the doors for a moment, because HW-D5 asks about them next.
3. Choose **Open** on compartment 1 in the admin panel.
4. Wait about 2 seconds, then cut the access point's power. Waiting gives the backend time to send the command. If you cut too early the unit never receives it, and if you cut too late the response is already delivered.
5. Wait 1 minute, then switch the access point back on and restore the panel's power.
6. Wait for the bank to show online. Wi-Fi retries back off to 30 seconds, so allow up to about 2 minutes. Check that your PC kept its address, because the unit has it stored as the broker URL.
7. Look for the response, and note its time:

   ```bash
   grep 'MQTT response message received' storage/logs/laravel.log | tail -3
   ```

8. Stop the broker for 1 minute, then start it again.

   ```bash
   docker compose stop mqtt
   ```

   ```bash
   docker compose start mqtt
   ```

9. Wait for the bank to show online. The unit reconnects within seconds, but the backend's listener can take about 40 seconds longer.
10. [Check that the unit did not restart](#prepare-for-the-manual-checks).

**Pass:** the bank recovered both times with no help, uptime kept rising, and the response is logged after the access point came back. Expect a failure result, because the panel had no power: this check is about delivery. The admin panel may already show the open as timed out.

If the response is logged before you cut the power, you cut too late, so repeat steps 2 to 7. That is not a fail.

Do not retry a failed open with `queue:retry`. It would send the old command again and could release the lock later than anyone expects.

#### HW-C9: Record memory after the outages

`hwtest.py` runs this straight after HW-C6. Selecting HW-C9 also runs HW-C6, because a restart between them resets the values.

1. Run `diagnostics` in the console.

**Pass:** the reply shows `min_free_heap` and `owner_stack_free`, and `uptime_seconds` has kept rising since HW-C6. Write both numbers in the note, because HW-H9 compares against them.

#### HW-C7 and HW-E5: Refuse an invalid token, then enroll again

`hwtest.py` asks once and records the answer for both. A wrong token must get one refusal and no retries. After a reset, the old MQTT user must be revoked and a real token must enroll the unit.

1. Note the current MQTT user. The commands on this page use the default `DB_USERNAME` (`sail`) and `DB_DATABASE` (`locker`), so replace them if your `.env` differs:

   ```bash
   docker compose exec db psql -U sail -d locker -Atc "select username,enabled,revoked_at from mqtt_users where locker_bank_id='<uuid>'"
   ```

2. Run `reset-network` in the console.
3. Make a token with the right format that the backend does not know:

   ```bash
   python -c "import secrets; print(secrets.token_hex(32))"
   ```

4. Commission with it, using your normal Wi-Fi and broker. For the local backend, the bootstrap user and password are `MQTT_PROVISIONING_USERNAME` and `MQTT_PROVISIONING_PASSWORD` from `locker-backend/.env`.

   ```bash
   python scripts/commission.py --usb COM3
   ```

5. After about a minute, look for one refusal:

   ```bash
   grep -E 'No LockerBank found|Publishing invalid token' storage/logs/laravel.log | tail -4
   ```

6. Wait 2 more minutes and run the same command. There should be no new lines.
7. Run `status` in the console. It should show `enrolled=0 pending=0 configured=1`.
8. Edit the bank in the admin panel, choose **Restart provisioning**, and copy the token. It is shown once and is valid for 24 hours.
9. Run the query from step 1. The old user should now be disabled, with a `revoked_at` time.
10. Run `commission.py` again with that token, and wait for online.
11. Run the query again.

**Pass:** one refusal, no retry, `pending=0`, the old user is revoked, a new user is active, and the bank goes online. The unit never shows its password, so this reads the revocation from the database instead of trying a login.

**Fail:** extra refusals from the same attempt, `pending=1`, or a real token that does not enroll. `pending=1` means the unit never processed the refusal.

#### HW-C8: Show the bank offline

1. Note the time, and unplug the ESP32.
2. Wait for the bank's **Heartbeat timeout (seconds)**, 30 by default, plus up to a minute for the scheduled check.
3. Plug it back in.

**Pass:** **Status** shows offline after the wait, and online again about a minute after you plug it back in. The backend does not act on the broker's Last Will message yet, so the heartbeat timeout is what changes the status.

### Group D step by step

The app checks need a user who has access to the compartment.

#### HW-D1 and HW-D4: Open the door by hand

`hwtest.py` asks once and records the answer for both.

1. Open the bank's compartments in the admin panel, and the compartment in the mobile app.
2. Send no command, and open the door by hand. If the lock holds it shut, release the latch by hand.
3. Close the door.
4. Look for the event:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Pass:** the panel and the app each follow within two seconds, the log shows `compartment_uncommanded_open`, and the **Audit Log** shows **Uncommanded door opening**.

#### HW-D3: Report a jammed door

This releases the lock, so hold the door shut.

1. Choose **Open** on compartment 1 in the admin panel.
2. Keep the door shut for longer than the bank's **Heartbeat interval**, which is the detection window.
3. Look for the event:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Pass:** the log shows `compartment_open_failed`, and the **Audit Log** shows **Door did not open**.

#### HW-D5: Lose and recover panel power

`hwtest.py` asks this right after HW-C9, so you can report what you saw during HW-C6's panel cut. If you did not watch it then, or run this alone:

1. Switch off the panel's power, not the ESP32's.
2. Wait for the next heartbeat, and watch the bank in the admin panel.
3. Switch the panel's power back on, and wait about a minute.

**Pass:** without power, **Hardware** shows unreachable and the doors show unknown. With power back, both recover with no restart.

### Group E step by step

#### HW-E2: Correct wrong Wi-Fi settings

Start with an enrolled, online unit. An enrolled unit only takes new Wi-Fi settings, so no token is needed.

1. Run `commission.py --usb COM3` with a wrong Wi-Fi password of at least 8 characters.
2. Wait until the bank shows offline.
3. Run `commission.py --usb COM3` again with the right password. Do not run `reset-network`.
4. Wait for online, then run `status` in the console.

**Pass:** the bank returns as the same bank, with `enrolled=1` and no new token.

### Group F step by step

Each check starts from a unit with [setup secrets](/dokumentation/esp32/flashing/#write-the-setup-secrets), unless it says otherwise. To reset a unit, run `reset-network` or hold the button for 10 seconds. A reset does not remove the setup secrets. The setup network opens for 10 minutes after power-up, and only on a unit with no settings.

#### HW-F8, HW-F1 and HW-F7: Refuse an expired token, then commission over the setup network

`hwtest.py` asks once and records the answer for all three, because a single commissioning gives all the results. This releases the lock, so stay at the cabinet. Use a throwaway Wi-Fi password and token for the capture.

1. Reset the unit, so it has no settings and its setup network opens.
2. Issue a token with **Restart provisioning**, and keep it for more than 24 hours. On a bench database only, you can make it old instead:

   ```bash
   docker compose exec db psql -U sail -d locker -c "update locker_banks set provisioning_token_issued_at = now() - interval '25 hours' where id='<uuid>'"
   ```

3. Commission with it, then look for the refusal:

   ```bash
   grep 'No LockerBank found' storage/logs/laravel.log | tail -2
   ```

4. Reset the unit again, and choose **Restart provisioning** for a fresh token.
5. Put the Wi-Fi password and the fresh token in a private file, one per line:

   ```bash
   install -m 600 /dev/null secrets.txt
   ```

6. Start a capture on your Wi-Fi interface:

   ```bash
   sudo tcpdump -i <wifi interface> -w setup.pcap
   ```

7. Unplug any USB data connection, power the unit, and commission [over the setup Wi-Fi](/dokumentation/esp32/commissioning/#over-the-setup-wi-fi-recommended).
8. Stop the capture with `Ctrl+C`, and search it for the secrets:

   ```bash
   strings -n 8 setup.pcap | grep -c -F -f secrets.txt
   ```

9. Delete `secrets.txt` and `setup.pcap`.
10. Wait for online, then choose **Open** on compartment 1.

**Pass:** the old token is refused, the fresh one enrolls the unit, the lock releases once, the count is 0, and the capture has traffic to `192.168.4.1`, including `ol-config`. The tool only reports "saved", so read success in the panel.

#### HW-F4, HW-F2 and HW-F3: Button, wrong Wi-Fi password and closed access point

`hwtest.py` asks once and records the answer for all three. Start with an enrolled unit. The button connects GPIO 4 to GND by default.

1. Hold the button for about 5 seconds and release it. `status` should still show `enrolled=1`.
2. Unplug the unit, hold the button, plug it in, and keep holding for more than 10 seconds before you release it. It should stay enrolled.
3. Unplug it, connect GPIO 4 to GND with a jumper, plug it in, and wait more than 10 seconds. It should stay enrolled.
4. Unplug it, remove the jumper, and plug it in.
5. Hold the button for 10 seconds and release it. The unit should restart with `enrolled=0 configured=0`.
6. In the admin panel, choose **Restart provisioning** and copy the token.
7. Run `commission.py --softap` with a wrong Wi-Fi password and that token.
8. Wait 2 minutes. The bank should stay offline, and the console should show repeated Wi-Fi retries.
9. Hold the button for 10 seconds, then release it. The unit wipes its settings and restarts.
10. Run `commission.py --softap` again with the right password and the same token, within 24 hours of issuing it. Wait for online.
11. List the Wi-Fi networks in range:

    ```bash
    nmcli device wifi list
    ```

12. Find the unit's address in your router, then probe the setup port:

    ```bash
    nc -zv -w 3 <unit address> 80
    ```

**Pass:** only steps 5 and 9 reset the unit, the unit keeps retrying the wrong password, the same token enrolls it, no `OpenLocker-` network from this unit appears, and the connection is refused or times out.

#### HW-F5: Protect the setup window

Start each part by resetting the unit and powering it up.

1. Join the setup network from your laptop, then try to join from a phone. The phone should be refused.
2. Power up again and wait 10 minutes. The setup network should disappear.
3. Power up again and run `commission.py --softap` with a wrong setup password. It should report `The setup password was not accepted`.
4. Repeat step 3 until you have 5 failures. The setup network should close.

**Pass:** all four results match.

#### HW-F6: Refuse bench commands in a production build

This needs a separate build. Flash your test image again before you continue, because `--image` must be the image on the unit.

1. Build and flash a [production image](/dokumentation/esp32/flashing/#choose-a-build).
2. Send each of these in the console: `configure {}`, `unlock 1 0`, `command {}`, `reset-network`.

**Pass:** each one answers `error invalid command`, and the lock does not move.

### Group H step by step

**The backend must be reachable over HTTPS with a certificate from a public CA.** The backend refuses to send a link that is not `https://`, and the firmware checks the certificate against its built-in CA bundle. A local `http://` backend cannot run this group.

Each release needs a new version, and the unit refuses a version it rolled back from. To make one, using the same key as the image on the unit:

1. Build it with the new version:

   ```bash
   LC_FIRMWARE_VERSION=0.2.0 idf.py reconfigure build
   ```

2. [Sign it](/dokumentation/esp32/flashing/#sign-the-image).
3. Upload `build/locker-firmware-signed.bin` under **Firmware releases**, with the same version.

To start an update, use **Update firmware** on the bank. The backend checks only the image's first byte and its size, so it accepts the broken images below on purpose. The console shows `downloading firmware` when a download starts, and `verified` when it succeeds.

The backend refuses a new update while one is in flight, and it times out an update that it hears nothing about after 30 minutes. Allow for that wait when a check cuts power or the network.

#### HW-H1: Confirm the run started on a signed image

There is no repeat run, because the rest of the run already covers it on this image.

1. Check that you started this run from a board you erased and flashed with the signed image, and that `--image` is `build/locker-firmware-signed.bin`.
2. Check that the bank's **Firmware** shows that image's version.

**Pass:** both are true. If you flashed anything else during the run, start again from a blank board.

#### HW-H2 and HW-H9: Update an idle unit and check its memory

`hwtest.py` asks once and records the answer for both. `min_free_heap` is the lowest value since boot, so a value read after a download includes it.

1. Make and upload a new release.
2. Start the update.
3. Run `diagnostics` when the console shows `verified`, before the unit restarts.
4. Wait for the unit to restart.

**Pass:** **Firmware** shows the new version, **Firmware update** shows completed after its trial, and `owner_stack_free` is at least the value recorded in HW-C9. Write both `diagnostics` values in the note.

#### HW-H3 and HW-H11: Open during an update and read the journal after a rollback

`hwtest.py` asks once and records the answer for both. This releases the lock, so stay at the cabinet. The way to force a rollback is a suggestion, because the check does not say how.

1. Start an update, and wait for `downloading firmware`.
2. Choose **Open** on compartment 1. The lock should release once, and the response should be logged.
3. Let the update finish.
4. Start another update. When the console shows the restart, switch off the panel's power, so the new image cannot reach the bus.
5. When the bank is online again, choose **Open** on compartment 1 during the 180-second trial.
6. Wait for the rollback, and find that open's `transaction_id` in the log.
7. [Send it again](#hw-h6-and-hw-h8-roll-back-and-keep-identity-mapping-and-journal). The older image should answer with its stored result and not unlock.
8. Restore the panel's power, and choose **Open** on compartment 1.

**Pass:** each open gets exactly one response and none is lost, the replay does not unlock, and the new open releases the lock once. The result during the trial is a failure, because the panel had no power.

#### HW-H4: Refuse bad signatures

1. Build and sign a new release, then damage a byte in its signature sector:

   ```bash
   python -c "import pathlib as p; f=p.Path('build/locker-firmware-signed.bin'); d=bytearray(f.read_bytes()); d[-3196]^=1; p.Path('build/bad.bin').write_bytes(d)"
   ```

2. Upload `build/bad.bin` as that version, and start the update.
3. Build a release with the next version, and sign it with another key.
4. Upload it, and start the update.

**Pass:** the console shows `firmware download failed` both times, **Firmware** keeps the old version, and **Firmware update** shows failed.

#### HW-H5: Survive a truncated image and a Wi-Fi cut

1. Build and sign a new release, then cut it short:

   ```bash
   head -c 600000 build/locker-firmware-signed.bin > build/short.bin
   ```

2. Upload `build/short.bin` as that version, and start the update.
3. Wait for the backend to show the failure. This can take up to 30 minutes.
4. Upload a release with the next version, and start the update.
5. After `downloading firmware`, switch off the access point for 1 minute.
6. Switch it back on, and wait up to 30 minutes for the backend to show the failure.

**Pass:** the old version keeps running both times, and the backend shows both updates failed.

#### HW-H6 and HW-H8: Roll back and keep identity, mapping and journal

`hwtest.py` asks once and records the answer for both. HW-H8 covers an update and a rollback, so do steps 2 to 6 twice: once after HW-H2 finished, before you start here, and once at the end. This releases the lock, so stay at the cabinet.

1. Run the MQTT user query from HW-C7 and note the user. Find the `transaction_id` of a completed open in the log.
2. Run `status`. It should show `enrolled=1`.
3. Run the query again. The MQTT user should be the same.
4. Run `diagnostics` and note `unlock_transmissions`.
5. Send that open again with a new `message_id`. The unit should answer with its stored result and not unlock. Set the two values from `locker-backend/.env` in this shell first:

   ```bash
   export BROKER_USER=<MQTT_USERNAME> BROKER_PASSWORD=<MQTT_PASSWORD>
   docker compose exec mqtt mosquitto_pub -h localhost -u "$BROKER_USER" -P "$BROKER_PASSWORD" -q 1 -t locker/<uuid>/command -m '{"message_id":"<new uuid>","transaction_id":"<transaction id>","action":"open_compartment","timestamp":"<now, ISO 8601>","data":{"compartment_number":1}}'
   ```

6. Run `diagnostics` again, then choose **Open** on compartment 1.
7. Upload a new release, and start the update.
8. As soon as the console shows `verified`, stop the broker with `docker compose stop mqtt`.
9. Wait more than 180 seconds after the unit restarts. It should restart again, into the old version.
10. Start the broker with `docker compose start mqtt`, and wait for online.
11. Do steps 2 to 6 again.

**Pass:** the unit returned by itself, **Firmware** shows the old version, **Firmware update** shows failed with reason rollback. Both times, the user is the same, one stored response comes back, the counter did not rise from the replay, and the compartment still opens.

#### HW-H7: Cut power while writing an image

The backend refuses a retry until the previous update times out, so ten rounds take about 5 hours.

1. Start an update, and wait for `downloading firmware`.
2. Unplug the ESP32 during the download.
3. Plug it back in, and check that it boots and goes online.
4. Wait for **Firmware update** to show failed.
5. Repeat until you have done this ten times.

**Pass:** it boots a valid image and goes online every time.

#### HW-H10: Boot an app from the next ESP-IDF patch

The unit keeps the bootloader you flashed over USB.

1. Build the same firmware with the next ESP-IDF patch release, using a new version.
2. Sign and upload it, then start the update.

**Pass:** the unit boots the new image, and **Firmware** shows its version.

If no newer patch release exists yet, choose **Not run** and say so in the PR. `hwtest.py check` keeps failing until this check passes or is deferred.

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
