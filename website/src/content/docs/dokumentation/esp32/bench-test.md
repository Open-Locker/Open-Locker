---
title: Bench test
description: Check the controller, the bus and one lock before the backend is involved.
sidebar:
  order: 5
---

This test shows that the ESP32 reaches the panel and can release one lock, with no Wi-Fi,
broker or backend involved. Do it before you commission a unit, because a fault found here
is much easier to tell apart.

## What the bench test proves

A unit with no network settings has no broker, so the USB console stands in for it. This
is bench mode. You type commands, and the unit prints what it would publish as lines
starting with `out`.

- You need a release or development build. A production build has no `unlock` or `command`.
- Bench mode ends as soon as network settings are stored.
- This is a user-sized part of hardware groups A and B, not a replacement for
  [the full checks](/dokumentation/esp32-hardware-acceptance/).

:::caution[This releases a lock]
Stay at the bench and keep the door of channel 1 clear.
:::

## Open the console

```bash
idf.py -p COM3 monitor
```

The console runs at 115200 baud. Your typing is not echoed, so the screen stays blank until
the reply. Every reply is one line starting with `ok` or `error`. You will also see
`out heartbeat ...` lines every 15 seconds, which you can ignore.

## Check the controller

1. Type `status`. You should see a reply like this, with no network settings stored:

   ```text
   ok bus=disconnected enrolled=0 pending=0 configured=0
   ```

2. Type `self-test`. It checks the frame encoder and sends nothing to the panel:

   ```text
   ok self-test passed
   ```

3. Type `diagnostics`. Note `unlock_transmissions`, which counts every unlock that reached
   the wire since boot. It should be `0`.

## Check the bus

1. Switch the panel supply on.
2. Type `query 1`.

   You should see `ok doors=`, then 32 characters, one per address from 0: `c` closed,
   `o` open and `?` unknown. Address 0 is channel 1. Channels the panel does not have read
   `?`.

   If you see `error query no_valid_reply`, see
   [Troubleshooting](/dokumentation/esp32/troubleshooting/#symptom-cause-fix).

3. Run `status` again. It should now say `bus=connected`.

## Release one lock

1. Check that the door of channel 1 is clear.
2. Type `unlock 1 0`, which means board 1, address 0. The lock releases once, and you should
   see:

   ```text
   ok unlock acknowledged
   ```

3. Type `diagnostics`. `unlock_transmissions` should be one higher.
4. Open and close the door by hand, typing `query 1` each time. The first character should
   go from `c` to `o` and back.

`unlock` is never retried. If you see `error unlock outcome_unknown`, the lock may or may not
have moved, so look at it before you try again.

## Try a real command

The `command` console command feeds one MQTT payload to the application, exactly as the
broker would. This also tests the mapping and the journal.

1. Send the mapping. This one has two compartments on board 1, and its hash is correct, so
   paste it as one line:

   ```text
   command {"message_id":"bench-msg-1","transaction_id":"bench-cfg-1","action":"apply_config","timestamp":"2026-04-14T19:31:00Z","data":{"config_hash":"3306f9a74f407226b567536b577fa7dfd460a674850ce9d1818aabe94f55ab62","heartbeat_interval_seconds":15,"adapter_type":"rs485_lock_board","feedback_type":"door_closing","compartments":[{"compartment_number":1,"slaveId":1,"address":0},{"compartment_number":2,"slaveId":1,"address":1}]}}
   ```

   You should see `ok command queued`, an `out response` line with `"result":"success"` and
   the same hash as `applied_config_hash`, and an `out snapshot` line.

2. Open compartment 1. The lock releases once:

   ```text
   command {"message_id":"bench-msg-2","transaction_id":"bench-open-1","action":"open_compartment","timestamp":"2026-04-14T19:32:00Z","data":{"compartment_number":1}}
   ```

   You should see `ok command queued` and an `out response` line with `"result":"success"`.
   Bench output carries a 1970 timestamp, because there is no wall clock without a network.

3. Send the same line again. The unit prints the stored response and the lock does not move,
   because a repeated `transaction_id` is never run twice. Check that `unlock_transmissions`
   in `diagnostics` did not change.

To open again, change both IDs. The mapping stays across a restart, and commissioning does
not depend on it: the backend sends its own mapping after enrollment.

## If it did not work

**`error invalid command`**: the unit does not know the word, or this is a production build.

**`error unlock disabled` or `error command not accepted`**: the unit has network settings, so
commands come from the broker only. `error command not accepted` also means the JSON could not
be parsed.

**A `response` line with `"result":"error"`**: the `error_code` in the line says why. See
the [error codes](/dokumentation/esp32/reference/#command-error-codes).

## Next

[Commission the unit](/dokumentation/esp32/commissioning/).
