# Locker Client — Production Readiness Checklist

## Feature parity

- [x] Provisioning via `PROVISIONING_TOKEN`
- [x] MQTT connect with saved credentials + LWT
- [x] `open_compartment` command + dedup + snapshot publish
- [x] `apply_config` with rollback
- [x] Heartbeat on `locker/{uuid}/state/heartbeat`
- [x] Retained snapshot on `locker/{uuid}/state/compartments`
- [x] Startup all-relays-off failsafe (ADR-0006)
- [x] Sequential three-board polling on beta hardware (ADR-0007/ADR-0029)

## Safety

- [x] MQTT unlimited reconnect configured (ADR-0014)
- [x] Waveshare flash-only open path (ADR-0004)
- [x] `flashDurationMs` within 100–500ms

## Tests

- [x] `pnpm test` green locally
- [x] Contract tests vs `docs/asyncapi/`

## Deployment

- [ ] Test on Raspberry Pi with real RS485 hardware
- [x] CI builds the rewrite from `locker-client/`
- [ ] Image `ghcr.io/open-locker/locker-client:dev` published from `dev`
- [ ] Image `ghcr.io/open-locker/locker-client:latest` published from `main`
- [ ] Merge the beta cutover to `dev`

## Follow-up hardening

Tracked in GitHub issues #166 and #168–#174. These are beta follow-ups and must
be completed before production rollout.
