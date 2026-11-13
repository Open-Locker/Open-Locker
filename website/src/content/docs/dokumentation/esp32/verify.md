---
title: Verify end to end
description: Confirm the commissioned locker bank works from the admin panel and the app.
sidebar:
  order: 7
---

These four checks show that a commissioned unit works through the real backend: it reports in,
opens a compartment, follows the door and recovers from an outage. They are a short version of
hardware groups C and D, which have no signed-off result yet, so a failure here may be a
firmware bug. Please [report it](/dokumentation/esp32/troubleshooting/#get-help-with-a-unit).

## The bank shows as connected

Open **Locker banks** in the admin panel. The row of your bank should show:

| Column | Expected |
| --- | --- |
| **Status** | online |
| **Hardware** | reachable. If it says unreachable, the unit is online but cannot reach the panel |
| **Firmware** | The version the unit reports. A build without `LC_FIRMWARE_VERSION` reports `0.0.0-dev` |

## Open a compartment

:::caution[This releases a lock]
Stay at the cabinet and keep the door clear.
:::

1. Edit the bank, and in its compartments choose **Open** on compartment 1. The lock should release
   once.
2. Open the same compartment from the mobile app with a user who has access to it. The lock should
   release once more.

## Door events reach the app

1. Open and close the door by hand.
2. Watch the admin panel and the app. Both should follow within about two seconds.

Now open the compartment from the panel and hold the door shut. The firmware reports the door as
jammed when its detection window ends. That window equals the bank's **Heartbeat interval**.

## Power and network loss

1. Unplug the unit. The bank should show offline once **Heartbeat timeout (seconds)** has passed. The
   default is 30 seconds. The backend does not act on the broker's Last Will message yet.
2. Plug it back in. It should reconnect with no help and show online again. It sends no unlock at
   start-up.
3. Switch off your Wi-Fi access point for a minute, then the broker. The unit should reconnect by
   itself each time.

## If it did not work

See [Troubleshooting](/dokumentation/esp32/troubleshooting/#symptom-cause-fix).

## Next

[Maintain and reset](/dokumentation/esp32/maintenance/).
