# ADR-0069: USB commissioning for the ESP32 bench client

## Status

Accepted for the integrated bench milestone. Revised 2026-10-08 at the user's
request, replacing the browser SoftAP decision. Field qualification pending.

## Date

2026-10-08

## Context

The initial implementation required USB to reveal a hotspot password and setup
key, then browser network switching to enter deployment settings. The user chose
USB setup instead. Local settings transfer and backend enrollment are distinct:
changing the former does not require a new backend provisioning protocol.

## Decision

Use physical USB serial at 115200 baud with a local Python setup tool. Prompt
for network/bootstrap settings and the one-time backend token; hide secret input
and do not write settings or raw device logs to disk. Do not expose a SoftAP or
HTTP setup service. Physical USB access authorizes local configuration.

Use bounded newline-delimited UTF-8 JSON commands and prefixed non-secret replies.
Reject malformed, oversized and NUL input; discard rejected or stalled partial
lines through their next newline. Apply settings on the serialized owner task,
validate before persistence, acknowledge only committed settings, then restart.
The tool never automatically repeats configuration or resets enrollment.

Keep backend MQTT enrollment unchanged: subscribe before sending the token,
commit uncertainty before registration, persist issued identity/credentials, and
clear bootstrap/token material. Lost replies require backend reset and a fresh
token. Network changes on enrolled devices preserve issued credentials, mapping
and safety history. Deliberate `reset-network` clears identity/mapping while
retaining the safety journal. Read existing version-1 NVS blobs without destructive
migration; former AP/setup-key fields remain reserved and unused.

## Alternatives Considered

- Browser SoftAP: added network switching while still requiring USB for secrets.
- Native phone provisioning: requires companion/mobile work beyond this milestone.
- Settings compiled into firmware: couples deployment secrets to build artifacts.

## Consequences

Installer needs a trusted computer, USB cable, serial driver and Python/pyserial.
There are no setup AP credentials, sessions or CSRF flows to manage. Local USB
access can change networking; enclosure/access policy requires field review.
Credentials remain plaintext at rest in this bench build. USB disconnection can
leave a saved configuration or uncertain enrollment; inspect status before
retrying and use deliberate reset/new-token recovery when required.

## References

- ADR-0048: One-time HMAC locker provisioning
- ADR-0050: Per-provisioning MQTT credential identities
- ADR-0068: Parallel ESP32 RS485 client
- `locker-client-esp32/README.md`
