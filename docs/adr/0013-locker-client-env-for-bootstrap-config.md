# ADR-0013: Use environment variables for locker client bootstrap connectivity

## Status

Accepted

## Date

2026-04-11

## Context

The locker client now separates server-managed runtime values from local
bootstrap/device values:

- runtime overlay for `heartbeatInterval` and `compartments`
- local base settings for MQTT bootstrap and Modbus bus access

The previous design still kept MQTT bootstrap connectivity values in
`/config/locker-config.yml`, even though these values are deployment-scoped and
fit naturally into `.env`:

- broker URL
- provisioning/default MQTT username
- provisioning/default MQTT password
- log level

Keeping these values in the YAML config increases the surface of the base file
without adding domain meaning. The logger already reads `LOG_LEVEL` from the
environment.

## Decision

Open-Locker uses environment variables for locker client bootstrap connectivity
settings:

- `MQTT_BROKER_URL`
- `MQTT_DEFAULT_USERNAME`
- `MQTT_DEFAULT_PASSWORD`
- `LOG_LEVEL`

The remaining `locker-config.yml` is reduced to local Modbus bus settings only.

Runtime values continue to be handled separately by the server-managed runtime
overlay.

## Alternatives Considered

### Alternative A: Keep MQTT bootstrap values in `locker-config.yml`

- Pros:
  - one visible config file for most local settings
  - no migration needed for current deploys
- Cons:
  - mixes deployment/bootstrap secrets with hardware config
  - duplicates an environment-driven pattern already used by `LOG_LEVEL`
  - keeps the YAML broader than necessary
- Why not chosen:
  - these values are operational bootstrap settings and fit better in `.env`

### Alternative B: Move all base config to `.env` immediately

- Pros:
  - maximal simplification of local config sources
  - no YAML file needed
- Cons:
  - larger migration in one step
  - Modbus bus settings become less structured and less discoverable
- Why not chosen:
  - keeping Modbus hardware settings in YAML is still practical for now

## Consequences

### Positive

- clearer split between deployment/bootstrap values and hardware config
- `.env.example` becomes the single place for MQTT bootstrap defaults
- `locker-config.yml` becomes smaller and more focused

### Negative

- deployments must now provide MQTT bootstrap values via environment variables
- there are still two local config sources instead of one

### Risks

- missing environment variables can break startup more abruptly than missing YAML
  fields that operators were already used to editing

Mitigations:

- provide a complete `.env.example`
- validate required MQTT bootstrap env vars explicitly in code
- update README/examples to reflect the new split

## References

- Supersedes: `docs/adr/0009-locker-client-runtime-config-overlay.md`
- Related docs:
  - `docs/adr/0010-direct-slaveid-modbus-addressing.md`
  - `docs/mqtt_integration_plan.md`
