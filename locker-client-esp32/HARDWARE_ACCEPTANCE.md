# ESP32 bench acceptance record

Record source commit (and dirty status), firmware SHA-256, board/panel model,
flash size, supply voltage, feedback wiring, and operator/date with each run.
Do not claim physical verification from the host fake-bus tests.

## Non-actuating checks

- [ ] Confirm board identity and enumerate its USB port before flashing.
- [ ] Run target smoke; record heap minimum and stack headroom after TLS/reconnect.
- [ ] Boot/reboot idle and observe zero new unlock transmissions.
- [ ] Run USB setup on a fresh unit; verify hidden inputs, saved reply and restart.
- [ ] Confirm no setup AP or HTTP listener appears before/after enrollment.
- [ ] Wrong Wi-Fi credentials permit USB correction without resetting identity.
- [ ] Malformed, oversized, NUL and stalled serial input never saves partial settings.
- [ ] Change Wi-Fi on an enrolled unit; identity, mapping and journal survive.
- [ ] Disconnect USB during save; inspect status before retrying and never
      assume a consumed one-time token can be reused.
- [ ] Observe bootstrap registration only after subscription; inspect logs for
      credential/token leakage without adding those logs to shared evidence.
- [ ] Invalid/expired token and lost enrollment reply follow backend reset and
      new-token recovery; old identities cannot authenticate or control the bank.
- [ ] Config survives a panel outage, rejects bad hashes/duplicate mappings,
      and requires a new mapping after enrollment reset.
- [ ] Physically open/close a feedback-equipped door, capture query-all frames,
      and establish bit order/polarity. Product channel count is not inferred.

## Attended actuation and faults

- [ ] One explicit open releases the selected lock; panel controls pulse duration.
- [ ] Repeated message ID and transaction ID produce one transmission total.
- [ ] Disconnect/disable the reply path after transmission: report uncertainty,
      never repeat the pulse, and observe sensor changes independently.
- [ ] Cut panel power and restore it; health becomes false and cooldown polling
      recovers without rebooting ESP or sending an unlock.
- [ ] Inject malformed, wrong-address, truncated and late replies; measure quiet
      intervals and confirm an old reply cannot acknowledge a newer request.
- [ ] Reconnect Wi-Fi and broker; pending responses resume and retained snapshot
      refreshes. Wrong/revoked broker credentials never enable operation.
- [ ] Interrupt device power at claim, transmit, completion and PUBACK stages;
      either recover a committed response/unknown outcome or stop on torn storage.
      In every case, no automatic second unlock is allowed.
- [ ] Confirm orderly local network changes report offline or trigger Last Will.
- [ ] Exercise unknown feedback streaks, jammed door detection, and uncommanded
      opening. Confirm reconnect does not publish false sensor transitions.
- [ ] Exercise the 64-transaction limit on a dedicated test unit; new commands
      refuse safely while prior outcomes remain recoverable.

## Before field release

- [ ] Resolve flash journal compaction, rollback protection and capacity under
      measured workload without shortening duplicate retention silently.
- [ ] Select/qualify hardware and enclosure, panel power/protection, grounding,
      strain relief and permanent connections.
- [ ] Accept trusted-time, secure-boot/flash-encryption and OTA decisions; do not
      burn development-board eFuses as part of this bench milestone.
- [ ] Define pilot SLOs/device-hours, then run outage/fault/soak campaigns and
      credential-safe Pi rollback against those thresholds.
- [ ] Coordinate firmware release/OTA control plane with issues #59 and #70;
      this factory-only partition table is not an OTA deployment layout.
