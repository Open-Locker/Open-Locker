# SoftAP provisioning: research note (#57)

Working note behind ADR-0074. Not a decision record.

## Options for a cable-free setup path

| Option | Verdict |
| --- | --- |
| ESP-IDF provisioning manager over SoftAP, Security 2 | Chosen. SRP6a key exchange, AES-256-GCM, supported tooling (`esp_prov`), one authenticated session. The firmware later used protocomm directly instead of the manager (ADR-0074 decision 5) |
| Provisioning manager over BLE | Same protocol, other radio. More RAM and an extra stack; no benefit for a fixed unit |
| Own HTTP server and HTML form on an open AP | Rejected. Secrets in clear, own parsing code on the device |
| Security 0 or 1 | Rejected. No authentication (0) or a weaker proof of possession (1) |

## What Security 2 gives, and what it does not

- Per-session keys derived from a per-device password (SRP6a), so a captured
  session cannot be replayed and the password is never sent.
- It protects the payload, not the existence of the network. The per-device WPA2
  passphrase on the access point keeps casual joiners out; the setup password
  protects the session itself.
- The provisioning manager's custom endpoint is expected to run inside the same
  session. The bench spike confirmed this for our `ol-config` payload: a capture
  shows neither the Wi-Fi password nor the token in clear (ADR-0074, open points).

## Risks found while planning

- Unauthenticated radio range: a unit opens its network only when it has no
  settings, for 10 minutes, one station at a time, five failed sessions closing it.
- A button is the only re-entry path, so no remote command can reopen it.
- Plaintext flash: bench builds only until flash and NVS encryption are enabled
  (release blocker, ADR-0074 decision 18).
- ESP-IDF carried an lwIP DHCP-server advisory (GHSA-g764-gwc3-75m5). The pinned
  release, 6.1 (ADR-0069), has the fix.
- Memory in AP+STA mode: measured at the spike, about 1.7 KB more than AP-only,
  with over 97 KB free in both. AP-only is enough, because the portal applies the
  credentials itself.

## Spike checklist (bench, throwaway branch)

See the table in ADR-0074. Results are written into the ADR before it is
accepted.
