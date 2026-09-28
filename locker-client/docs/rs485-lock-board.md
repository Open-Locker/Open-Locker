# RS485 Lock Board

The locker client drives one kind of locker board: the dedicated 8-channel RS485
electric-lock controller with DIP addressing and a proprietary hexadecimal
protocol. The client talks to it through a USB-to-RS485 adapter on the Raspberry
Pi. See [ADR-0067](../../docs/adr/0067-rs485-lock-board-behind-a-protocol-neutral-bus-port.md).

The board is one adapter behind `LockerBusPort`. Another controller can be added
as a further adapter without changing the application layer.

## Base configuration

`locker-config.yml` contains only the serial device:

```yaml
serial:
  port: /dev/serial/by-id/usb-FTDI_USB_RS485-if00-port0
```

Prefer a `/dev/serial/by-id/...` path: `/dev/ttyUSB0` can change when devices are
re-plugged or the Pi reboots. Map the same device into the container in
`docker-compose.yml`.

The board firmware fixes the serial framing, so the adapter does too:

| Setting               | Value                                    |
| --------------------- | ---------------------------------------- |
| Baud rate             | 9600                                     |
| Framing               | 8 data bits, no parity, 1 stop bit       |
| Flow control          | none                                     |
| Response timeout      | 1500 ms (the unlock reply takes ~500 ms) |
| Frame quiet time      | 25 ms of silence completes a response    |
| Inter-transaction gap | 5 ms                                     |
| Reconnect             | 5 attempts, 5 s apart, 60 s cooldown     |

A file with the older `modbus:` block still works: its `port` is read and every
other key is ignored with a warning. Rename the block to `serial:` when you next
edit the file.

## Runtime addressing

The backend delivers the hardware profile and compartment mapping via
`apply_config`:

```json
{
  "adapter_type": "rs485_lock_board",
  "feedback_type": "door_closing",
  "compartments": [
    { "compartment_number": 1, "slaveId": 1, "address": 0 },
    { "compartment_number": 2, "slaveId": 1, "address": 1 }
  ]
}
```

- `slaveId` is the board's DIP address, `1..31`. The vendor text mentions
  `1..99`; only `1..31` is supported.
- `address` is the zero-based channel on that board. The adapter sends it on the
  wire as `address + 1`, so `address: 0` is the board's channel 1.
- `adapter_type` accepts only `rs485_lock_board`. `waveshare_modbus` is rejected.
- One board per RS485 bus is the supported configuration until a poll cycle
  with several boards has been measured on real hardware. Several `slaveId`
  values are accepted, but not yet supported.

Until a profile has been applied, MQTT stays online, open commands fail with
`RUNTIME_CONFIG_NOT_APPLIED`, and no serial port is opened. A client that still
holds a profile from a Waveshare installation starts the same way until the
configuration is sent again from the admin panel.

## Feedback

Each unlock reply carries one status byte, `00` or `11`. The query-all reply
carries one bit per channel. Both are mapped through the bank's `feedback_type`:

| `feedback_type` | Signal `00` / low | Signal `11` / high |
| --------------- | ----------------- | ------------------ |
| `door_closing`  | `open`            | `closed`           |
| `door_opening`  | `closed`          | `open`             |

The unlock reply is the first door observation after an open command: `open`
reports `opened` straight away, anything else continues query-all polling until
the door opens or the detection window ends with `door_jammed`.

## Protocol behaviour

- Every frame is checked with an XOR/BCC over all bytes.
- Query-all response length is taken from the received frame; only configured
  channels are read from it, and missing bits become `unknown`.
- A reply that does not match the request's header, board, and channel is
  rejected.
- After a timeout or a bad frame the client waits for the line to go quiet and
  discards all input, so a late reply is never read as the next answer.
- An unlock is never sent twice on its own. If its reply is lost, the command
  fails and the door is watched by normal polling.
- Unlocks go ahead of queued polls.
- A board that does not answer reports its doors as `unknown`.
- Unsolicited `0x82` feedback, full-open, and multi-channel unlock commands are
  not used.

## Commissioning

1. Set a unique DIP address on each board and note it.
2. Wire the RS485 bus (A/B, ground) from the USB adapter to the board.
3. Set `serial.port` to the adapter's `/dev/serial/by-id/...` path.
4. In the admin panel, map each compartment to its board's DIP address
   (`slaveId`) and zero-based channel (`address`), choose the feedback type of
   the installed locks, and send the configuration.
5. For every compartment: open it from the app or admin panel, confirm the right
   door releases, and check that the reported door state matches the physical
   door when it is open and when it is closed. A state that is always inverted
   means the wrong `feedback_type`.

## Not yet validated on hardware

The protocol handling is tested against the vendor documentation and one
captured query-all frame. Before production use, the reference build still has
to confirm the unlock pulse, the quiet time on the chosen USB adapter, feedback
semantics and contact bounce, power and protection, RS485 wiring, multi-board
polling, and USB reconnect (ADR-0067, Rollout).
