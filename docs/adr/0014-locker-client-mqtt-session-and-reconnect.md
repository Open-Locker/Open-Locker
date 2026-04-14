# ADR-0014: Locker-client MQTT session defaults and reconnect policy

## Status

Accepted

## Date

2026-04-14

## Context

The locker-client must survive temporary broker or network outages on deployed
hardware. Issue [#37](https://github.com/Open-Locker/Open-Locker/issues/37)
noted that the previous implementation used `clean: true` and stopped the MQTT
client after five reconnect attempts, which could leave a cabinet permanently
disconnected until process restart.

The integration plan (`docs/mqtt_integration_plan.md`) expects IoT clients to
use persistent MQTT sessions (`clean_session = false`) so the broker can buffer
QoS 1 traffic while the client is offline.

## Decision

1. **Default `clean: false`** for the locker-client MQTT connection (overridable
   via `MQTT_CLEAN_SESSION` or YAML `mqtt.cleanSession`).
2. **Default unlimited automatic reconnects** (`maxReconnectAttempts` = 0). If
   set to a positive value, the client stops after that many reconnect cycles
   (optional safety cap for lab use).
3. **Expose connection state** on `mqttClientManager`: `disconnected` |
   `connecting` | `connected` | `reconnecting`. When the broker link is lost
   and `reconnectPeriod > 0`, the client enters `reconnecting` and relies on
   mqtt.js until the session is restored.
4. **Configuration** for keepalive, reconnect period, connect timeout, clean
   session, and max reconnect attempts via environment variables and optional
   YAML under `mqtt.*` in the locker `config.yml`.

## Alternatives Considered

### Alternative A: Process exit after N failures

- Pros: Obvious failure mode for monitoring.
- Cons: Requires external restart; poor fit for edge devices.
- Why not chosen: Reliability goal is self-healing reconnect.

### Alternative B: Application-level reconnect loop (tear down and new `mqtt.connect`)

- Pros: Full control over backoff and logging.
- Cons: Duplicates mqtt.js behavior; more code to maintain.
- Why not chosen: mqtt.js already implements reconnect with `reconnectPeriod`.

## Consequences

### Positive

- Cabinets recover automatically after broker maintenance or flaky networks.
- Persistent sessions align with QoS 1 buffering expectations.
- Operators can inspect `getConnectionState()` for diagnostics.

### Negative

- Stale session state on the broker if the client ID is reused incorrectly
  (mitigated by stable persisted client id).
- Unlimited reconnect can mask chronic network issues (address via monitoring
  and logs).

### Risks

- Misconfigured `reconnectPeriod: 0` disables automatic reconnect; documented in
  config.

## Rollout / Migration

- Deploy new client; existing env files work without changes (new defaults apply).
- To preserve old behavior (`clean: true`), set `MQTT_CLEAN_SESSION=true`.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: [#37](https://github.com/Open-Locker/Open-Locker/issues/37)
- Related docs: `docs/mqtt_integration_plan.md`, `locker-client/src/config/mqtt.ts`,
  `locker-client/src/mqtt/mqttClientManager.ts`
