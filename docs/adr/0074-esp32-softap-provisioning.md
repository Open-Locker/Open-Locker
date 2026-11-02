# ADR-0074: ESP32 commissioning over a SoftAP setup network

## Status

Accepted

## Date

2026-10-19

## Context

Commissioning over USB needs a cable and a computer with ESP-IDF tools at every
unit. Issue #57 asks for commissioning with no cable: the unit opens a setup Wi-Fi
network (SoftAP), and an installer joins it and hands over Wi-Fi settings and the
one-time provisioning token.

A setup network is an attack surface that USB is not. Anyone in radio range can see
it, and the unit later actuates locks. The design has to limit when the network
exists and what can bring it back. How the session itself is protected is
[ADR-0083](0083-esp32-setup-session-security-2.md).

## Decision

We will commission production units only over a setup network that exists only on
a unit with nothing stored, and that only a physical gesture can bring back.

- A unit with **no network settings** opens its setup network on every power-up,
  for **10 minutes**. It never opens on a unit that has settings or is enrolled, and
  never because of an MQTT message or a console command.
- The window closes after saving, after 10 minutes, and after five failed sessions.
  One station may be connected at a time.
- The only way back is a button on **GPIO 4** (to GND, internal pull-up) held for
  **10 s** and released. A hold of 30 s or more is void. A press counts only after
  the button was seen released for 1 s, so a button held at power-up or a stuck pin
  does nothing. It wipes identity, mapping and settings, and keeps the command
  journal and the setup secrets.
- Changing Wi-Fi on a deployed unit means a reset plus a new token from the admin
  panel.
- USB commissioning (`scripts/commission.py --usb`, the `configure` command) stays
  for bench builds. Production builds do not have it (`CONFIG_LC_BENCH_CONSOLE` off).
- The portal is a second driving adapter over the same `commission_device` use case,
  and has no path to the bus port: the layer rule
  `"lc_softap_portal": ["lc_domain"]` enforces it.

The wipe order, boot repair and installer tool are in
`website/src/content/docs/dokumentation/esp32-internals.md`.

## Rationale

The setup network exists only when the unit has nothing to protect yet and someone
is physically there. A physical gesture cannot be triggered remotely, so no message
or console line can make a deployed unit reachable by radio.

## Alternatives Considered

### Alternative A: Opening the portal from MQTT or the console, or on every failed Wi-Fi connection

- Pros: remote recovery from wrong Wi-Fi settings.
- Cons: it would make the setup network reachable on deployed units.
- Why not chosen: the physical gesture is the boundary.

### Alternative B: BLE provisioning

- Pros: same protocol, no Wi-Fi joining.
- Cons: an extra radio stack and more RAM, with the same installer-tool problem.
- Why not chosen: no advantage for a fixed locker.

## Consequences

### Positive

- No cable or ESP-IDF tools needed at a cabinet, beyond the laptop tool.
- A unit in the field exposes no network of its own.

### Negative

- A unit commissioned with wrong Wi-Fi settings keeps retrying and cannot be fixed
  without the button and a reset. The same unused token still enrolls it within 24
  hours of issue ([ADR-0082](0082-provisioning-tokens-expire-after-24-hours.md)).
- A unit sends its token once. If the broker drops the registration (seen on the
  bench when the broker's auth check timed out), the unit only listens for a late
  reply and needs a reset; the token was not spent, so it enrolls the unit again.
- Production builds lose `configure`, `unlock`, `command` and `reset-network` on the
  USB console.

### Risks

- Credentials and the setup password sit unencrypted in NVS, because nothing in the
  firmware turns flash or NVS encryption on yet. They can be read from a stolen unit,
  so the firmware is for an attended bench only. This is a release blocker: no
  production unit may ship before encryption is on, and that change needs its own ADR.

## Rollout / Migration

None on the firmware side: no unit is deployed.

## Confirmation

- When the network exists: S6-H03, S6-H05, S6-H06, S6-H07, S6-H08, S6-H13; HW-F3 and
  HW-F5.
- The gesture: S6-H16 to S6-H23, S6-H25; HW-F4. The jumper check of HW-F4 has not run
  yet.
- Reset and boot repair: S6-H15, S6-H24, S6-H26; HW-F2.
- No path to the bus: S6-H12.
- Production console: S6-H14, HW-F6.
- End to end: HW-F1.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#331
- Related issues: #57, #341
- Related docs: `website/src/content/docs/dokumentation/esp32-internals.md`,
  [ADR-0069](0069-esp32-locker-firmware.md),
  [ADR-0070](0070-esp32-command-journal-format.md)
