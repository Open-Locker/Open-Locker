# locker-client v2 — Cutover Checklist

## Feature parity

- [ ] Provisioning via `PROVISIONING_TOKEN`
- [ ] MQTT connect with saved credentials + LWT
- [ ] `open_compartment` command + dedup + snapshot publish
- [ ] `apply_config` with rollback
- [ ] Heartbeat on `locker/{uuid}/state/heartbeat`
- [ ] Retained snapshot on `locker/{uuid}/state/compartments`
- [ ] Startup all-relays-off failsafe (ADR-0006)
- [ ] Sequential multi-board polling (ADR-0007)

## Safety

- [ ] MQTT unlimited reconnect (ADR-0014)
- [ ] Waveshare flash-only open path (ADR-0004)
- [ ] `flashDurationMs` within 100–500ms

## Tests

- [ ] `pnpm test` green in CI
- [ ] Contract tests vs `docs/asyncapi/`

## Deployment

- [ ] Test on Raspberry Pi with real RS485 hardware
- [ ] Image `ghcr.io/open-locker/locker-client:v2` published
- [ ] PR merge; archive `locker-client/` v1
