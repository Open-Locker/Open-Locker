# ESP32 locker client foundation

ESP-IDF project managed by PlatformIO, targeting the WROOM-32-based WEMOS D1
MINI ESP32. This is not the ESP8266 NodeMCU V3. The executable check accepts
manual status queries and single-channel unlock commands over USB. UART communication
lives in the `lock_bus` component so later application code can reuse it.

## Wiring

Disconnect power while wiring. Power the ESP32 through a USB data cable.

| XY-485 TTL terminal | ESP32 board label |
| --- | --- |
| VCC | 3V3 |
| GND | GND |
| TXD | IO17 (ESP32 TX) |
| RXD | IO16 (ESP32 RX) |

This XY-485 labels its TTL terminals from the controller's perspective:
controller TX connects to TXD, and controller RX connects to RXD. This wiring
was confirmed working on the physical ESP32 setup; do not cross these labels.

Connect XY-485 A to panel 485A and B to panel 485B. No panel-to-converter signal
ground wire was confirmed in the hardware handoff. Consult the actual converter
and panel documentation before changing that reference; a surge/earth terminal
is not automatically signal ground. Power the
panel separately at a voltage compatible with both the panel and connected
locks; its specified input range is 12–24 V. Never connect that supply to ESP32
GPIOs or 3V3. The documented XY-485 supports 3.3 V logic and automatically
controls direction, so no DE/RE connection is needed.

The selected board profile specifies 4 MB flash; `sdkconfig.defaults` keeps the
ESP-IDF flash setting aligned. The PlatformIO platform is pinned to 7.1.3, using
ESP-IDF 6.1. A small pre-build script supplies the generated bootloader linker
script search path missing from that platform's integration.

Set the panel DIP address to **1** using the panel's ON markings (switch 1
contributes 1; switches 2–5 contribute 2, 4, 8, 16). Its documented default baud
rate is 9600. This test uses 8N1, with no flow control; the seller omits parity
from its settings, so verify it on actual hardware.

## Build, upload, and observe

Open this folder in VS Code with PlatformIO. Run **PlatformIO: Build**, then
**PlatformIO: Upload**, then **PlatformIO: Serial Monitor** from the command
palette. The monitor uses 115200 baud; panel UART2 uses 9600 baud.

Equivalent commands from this folder:

```sh
pio run
pio run --target upload
pio device monitor --baud 115200
```

If automatic port selection is ambiguous, set `upload_port = COMx` and
`monitor_port = COMx` in `platformio.ini` using your actual Windows port.

The monitor buffers input until Enter. Type exactly one lowercase command:

- **`o` + Enter:** transmit `8A 01 01 11 9B`, unlocking channel 1 on board 1.
  One command is sent, with no automatic retry and no wait for acknowledgement.
  The panel controls pulse duration. `Unlock transmitted` confirms only UART
  transmission; inspect the physical lock to determine whether it actuated.
- **`s` + Enter:** send `80 01 00 33 B2` to query all channels on board 1. The
  monitor prints received bytes and validates header, address, command, and XOR.
  No reply is reported after one second.

No commands are sent on startup or periodically. Empty lines and the extra
newline in CRLF do nothing. Multi-character/unknown commands are rejected.
The unlock command never addresses channel zero (open all). Responses to unlock
are not read by this manual test; a subsequent status query discards stale input.
The verified 8-channel query response in Open-Locker is
`80 01 00 00 00 33 B2`; status bytes change with door feedback.

## Acceptance on hardware

1. Confirm the startup message appears over USB.
2. With panel powered and address 1 selected, send `s` + Enter and confirm a
   valid status reply.
3. Send `o` + Enter once and observe channel 1's lock. Confirm no additional
   unlock transmissions appear while idle or after restarting the ESP32.
4. Open/close a door manually, send `s` + Enter, and inspect raw status bytes if its
   feedback wires are connected. Raw bytes do not yet establish door polarity.
5. Remove panel power and send `s`: confirm no reply. Restore power and send `s`
   again to confirm communication recovers without restarting the ESP32.

This is a lasting starting point, not a production-ready MQTT client. The bus is
owned by one task. The test uses a bounded 64-byte receive buffer and a
conservative 20 ms quiet period at 9600 baud; production serial timing, recovery,
configuration and command persistence must follow the existing contracts.

Protocol reference: [ADR-0061](../../docs/adr/0061-backend-managed-rs485-locker-board-profile.md).
