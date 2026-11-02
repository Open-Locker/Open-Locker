---
title: Troubleshooting
description: Symptoms, causes and fixes for ESP32 locker controllers.
sidebar:
  order: 8
---

Find what you see in the tables below. Quoted text is the exact message from the tool, the console or
the log. To read the log, run `idf.py -p COM3 monitor` and restart the unit.

## Symptom, cause, fix

### Bus and locks

| What you see | Cause | Fix |
| --- | --- | --- |
| `error query no_valid_reply`, or `bus=disconnected` | Panel without power, wrong wiring, or a wrong address | Check the panel supply, A to A and B to B, RXD and TXD, and the DIP address |
| `bus=unreachable` | Repeated failures started a cooldown, in which nothing is written. After 5 failures it lasts 60 s | Fix the cause and wait. No restart is needed |
| `query` shows every door as `o`, and `unlock` gets no acknowledgement | The bus only echoes requests: a wiring fault | Check the wiring from the converter to the panel |
| `error unlock outcome_unknown` | No usable reply came within 1500 ms. The lock may have moved | Look at the lock. Never assume it stayed shut. The bus then stays silent for 3 s |
| `error unlock not_sent` | The request did not reach the wire, for example during a cooldown | Wait for the cooldown to end |
| `error unlock refused` | The panel answered with its failure status | Check the lock and the channel |
| A response with `RUNTIME_CONFIG_NOT_APPLIED` | No mapping yet | Use **Send config to client** in the panel, or send `apply_config` on the bench |

### Commissioning and enrollment

| What you see | Cause | Fix |
| --- | --- | --- |
| The setup network does not appear | It only exists for 10 minutes after power-up, and only on a unit with no settings and with setup secrets. Log: `no setup secrets stored: the setup network stays closed` | Power-cycle the unit, or [write the secrets](/dokumentation/esp32/flashing/#write-the-setup-secrets) |
| `No reply from the device. Join its setup Wi-Fi ...` | The laptop is not on the setup network, or the 10 minutes ended | Power-cycle the unit, join the network, and run the tool again |
| `The setup password was not accepted.` | Wrong setup password. Five failed sessions close the network until the next restart | Check the label |
| `The device refused the settings. Nothing was saved.` | A value failed validation | Check the values, then run the tool again |
| `esp_prov or its dependencies were not found` | No firmware build in this checkout, or missing Python packages | Build the firmware, then `pip install cryptography protobuf` |
| `No board found (no CH9102 USB-serial chip).` | The board has another chip, or is not plugged in | Name the port: `--usb COM3` |
| `An enrollment is pending on this device ...` | The token was sent, and no answer arrived. The unit [never sends it twice](/dokumentation/esp32/commissioning/#the-token-is-used-once) | Choose **Restart provisioning**, reset the unit, and commission it with the new token |
| Log: `the backend refused the provisioning token` | The token was used, was replaced, or is older than 24 hours | The same fix |
| `The broker URL must look like mqtts://host:port` or `error invalid_settings` for `mqtt://` | A release or production build takes `mqtts://` only | Use `mqtts://`, or a [development build](/dokumentation/esp32/flashing/#choose-a-build) |
| `The broker URL points at ..., which is the device itself.` | `localhost` means the unit, not your laptop | Use the laptop's network address |
| Log: `refusing a broker URL that is not mqtts://` | A URL stored by a development build, now on a release build | Reset the unit and commission it again with `mqtts://` |

### Network and broker

| What you see | Cause | Fix |
| --- | --- | --- |
| Log: `the network is up but the time is not set (NTP blocked?); not connecting` | The certificate check needs the date. The unit uses `pool.ntp.org` and waits until it gets the time | Allow NTP on that network |
| Log: `transport error: tls=...` | The broker's certificate does not chain to a public CA, or its name does not match. Verification cannot be turned off | Use a certificate from a public CA. A private CA has to be added to the build |
| Log: `the broker refused the connection (code ...)` | Wrong credentials, or the identity was revoked by a provisioning reset | Reset the unit and commission it with a new token |
| Log: `the broker refused subscription ... (check its ACL)` | The broker's access rules do not allow the topic | Check the [broker setup](/dokumentation/operations/) |

### Journal

| What you see | Cause | Fix |
| --- | --- | --- |
| Log: `the command journal is not healthy: commands are refused until it is erased` | A corrupt record, a failed flash write or erase, or a missing `journal` partition. A power cut does not cause this. Nothing actuates until it is fixed | Erase the partition, see below, and commission again |
| Commands refused with `UNKNOWN_ERROR` | 32 responses are waiting for the broker | Fix the broker connection. Stored responses are delivered first, which clears the limit |

To erase the journal:

```bash
parttool.py --port COM3 --partition-table-offset 0xC000 erase_partition --partition-name journal
```

### Over-the-air updates

| What you see | Cause | Fix |
| --- | --- | --- |
| No **Update firmware** action on the bank | The unit did not report the `firmware_update` capability | Wait for a heartbeat from a firmware that has it |
| Update error `UPDATE_VERIFY_FAILED` | Bad signature, another key, or the wrong version inside the image | [Sign the image](/dokumentation/esp32/flashing/#sign-the-image) with the key of the running image |
| Update error `UPDATE_VERSION_REJECTED` | The unit rolled this version back | Publish a new release with the next patch version |
| Update fails with reason `timeout` | No result 30 minutes after the request | Check the unit's connection and try again |

The [Reference](/dokumentation/esp32/reference/#command-error-codes) lists every code.

## Read the boot log

The boot log tells you which mode the unit started in:

| Log line | Meaning |
| --- | --- |
| `no network settings: bench mode, commands come from the console` | No settings stored |
| `setup network open for 600 s` | The setup network is open |
| `a half-finished reset was found and finished` | A reset was interrupted, and the unit completed it |
| `enrolled; restarting to connect with the issued identity` | Enrollment worked |
| `DEVELOPMENT BUILD: connecting without TLS, credentials travel in clear` | A development build, on an `mqtt://` broker |

## Get help with a unit

Ask on [Discord](https://discord.gg/rZ74RYKN3H) or open a
[GitHub issue](https://github.com/Open-Locker/Open-Locker/issues). Include:

- the output of `status` and `diagnostics`, which contain no secrets;
- the firmware version and which build you used;
- the boot log, after you have checked it for anything private.

Never post the label's secrets, a token or a Wi-Fi password.
