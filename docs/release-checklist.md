# Beta Release Checklist

Open Locker has **no production deployment yet**. This beta is a controlled
pre-production pilot with named testers, selected Raspberry Pis, and an explicit
stop/rollback decision. It is not approval for an unrestricted production
rollout.

Use this checklist with the
[beta rollback runbook](releases/beta-rollback.md). The operational decision is
recorded in
[ADR-0052](adr/0052-controlled-beta-release-and-rollback.md); component tags
follow [ADR-0043](adr/0043-monorepo-release-strategy.md).

## Release record

- [ ] Release owner:
- [ ] Backend operator:
- [ ] Pi/client operator:
- [ ] Mobile operator:
- [ ] Pilot site and tester group:
- [ ] Planned start, decision time, and monitoring window:
- [ ] Previous tags: `backend-v________`, `client-v________`,
      `mobile-v________`
- [ ] Candidate tags: `backend-v________`, `client-v________`,
      `mobile-v________`
- [ ] Database backup identifier and timestamp:
- [ ] Pi configuration and `/data` backup location:

## 1. Hard gates

Do not synchronize `dev` to `main`, create release tags, submit store builds, or
re-provision a locker until every hard gate is closed and verified.

- [ ] #50 — per-component release workflows and notes are operational with
      `backend-v*`, `client-v*`, and `mobile-v*`.
- [ ] #134 — stale `open_compartment` commands have an accepted and tested age
      policy; delayed commands cannot unexpectedly open a door.
- [ ] #163 — MQTTS is verified end to end through the selected production
      Traefik adapter, and plaintext port 1883 is confirmed unreachable from
      outside the Docker network.
- [ ] #169 — the Raspberry Pi soak test has completed for the agreed duration
      with recorded logs, resource use, reconnect behavior, and hardware result.
- [ ] #209 — the beta scope and release-readiness issue is closed or has explicit
      release-owner acceptance with no unresolved blocker.
- [ ] #67 — complete the Beta task and record its outcome. It is required Beta
      work even though it is tracked separately from the hard-gate list above.

## 2. Candidate and branch preparation

- [ ] Confirm all intended changes are integrated and reviewed on `dev`.
- [ ] Confirm no open Beta change is represented as shipped merely because it
      exists in a pull request.
- [ ] Run the complete component checks on the exact candidate commit:
  - [ ] Backend: `cd locker-backend && composer quality`
  - [ ] Locker client: `cd locker-client && pnpm check && pnpm test`
  - [ ] Mobile: `cd mobile-app && pnpm check && pnpm test:ci`
  - [ ] MQTT contract CI and any cross-component integration checks pass.
- [ ] Regenerate and verify the mobile API client from the live candidate
      backend when the OpenAPI contract changed.
- [ ] Review API, MQTT, schema, and migration compatibility in the release
      notes, including minimum supported component versions.
- [ ] Merge `dev` into `main` through the normal reviewed synchronization path.
- [ ] Verify `main` contains the exact candidate and rerun required protected
      branch checks. Merging to `main` is not itself a release.

## 3. Version and artifact preparation

- [ ] Choose independent SemVer prerelease tags only for changed components, for
      example `backend-v1.0.0-beta.1`, `client-v1.0.0-beta.1`, and
      `mobile-v1.0.0-beta.1`.
- [ ] Confirm each tag will point to the intended commit on `main`.
- [ ] Confirm non-`main` and manual workflow runs cannot overwrite `latest` or
      mint a SemVer release.
- [ ] Create each required component tag using the approved release process.
- [ ] Verify each generated release contains component-scoped notes, contract
      changes, migration instructions, known risks, and rollback tags.
- [ ] Backend image exists under the immutable version tag and reports that
      version from `GET /api/identify`.
- [ ] Client multi-architecture image exists under the immutable version tag,
      has the expected digest, and is pullable on the target Pi architecture.
- [ ] Mobile artifacts identify the intended version/build and originated from
      the tagged or approved `main` release path.
- [ ] Record immutable tags and image digests. Do not deploy `latest` to the beta
      pilot.

## 4. Backups and rollback readiness

- [ ] Take a database backup immediately before rollout; record its timestamp,
      size, storage location, and readability/restore verification.
- [ ] Review migrations for old-backend/new-schema compatibility.
- [ ] State whether backend code rollback is safe without schema rollback.
- [ ] If restore might be required, state the maximum accepted data-loss window
      and who may authorize it. Restore remains the last resort.
- [ ] Back up Pi `config/` and `/data` without changing permissions or deleting
      client identity, credentials, runtime overlay, or dedup state.
- [ ] Confirm previous immutable component tags remain pullable/installable.
- [ ] Review and assign every step in the
      [beta rollback runbook](releases/beta-rollback.md).

## 5. MQTT credential rollout (PR #227)

The order is mandatory. Existing legacy credentials continue to work until a
bank is deliberately re-provisioned.

- [ ] Deploy the compatible `client-v*` image to every pilot Pi first and verify
      it persists `lockerUuid` separately from the opaque MQTT username.
- [ ] Do **not** reset or re-provision any locker bank before the compatible
      client is confirmed.
- [ ] Deploy the backend image and database migration that add identity mapping,
      revocation metadata, and the updated provisioning reply.
- [ ] Restart all queue workers after the backend deployment; queued reactors
      must not continue running old credential-issuance code.
- [ ] Verify legacy credentials still authenticate and remain scoped to their
      own locker topics.
- [ ] Re-provision only the designated pilot bank, then verify one enabled
      identity, a new opaque username, correct locker UUID topics, and denial of
      the revoked identity.
- [ ] Do not delete or reuse revoked identity rows.

## 6. Modbus acceptance (PR #228)

- [ ] Record the exact Pi, USB/serial adapter, Waveshare board, port, and client
      tag used for acceptance.
- [ ] Confirm startup completes safely when the bus is temporarily unavailable
      and does not energize a relay.
- [ ] Unplug the adapter long enough to exhaust a reconnect cycle; verify the bus
      becomes unreachable without a process restart loop.
- [ ] Reconnect the adapter; verify polling initiates a later reconnect cycle and
      returns to connected without operator restart.
- [ ] Confirm observed serial error codes match the reconnectable classification
      assumed by PR #228 and
      [ADR-0051](adr/0051-modbus-reconnect-declares-an-unreachable-bus.md). Any
      unclassified real-device error blocks acceptance.
- [ ] Verify heartbeat/admin hardware status, serialized Modbus operations,
      Waveshare hardware-flash pulse limits, and one supervised open cycle.
- [ ] Attach this evidence to the #169 Pi soak result.

## 7. Pre-production deployment and smoke tests

- [ ] Deploy the pinned backend tag, run migrations, and restart long-running
      services and queue workers.
- [ ] Verify backend health, database, Redis, scheduler, queue/event workers,
      Reverb, MQTT listener, and admin sign-in.
- [ ] Perform an external MQTTS smoke test using the deployed certificate trust
      path: connect, authenticate, publish/subscribe only to authorized topics,
      and verify a deliberately unauthorized topic is denied.
- [ ] Confirm plaintext MQTT is not accidentally exposed where #163's accepted
      beta design forbids it.
- [ ] Deploy the pinned client tag to the selected Pi and verify image digest,
      online heartbeat, Modbus reachability, retained compartment snapshot, and
      stable reconnect behavior.
- [ ] Complete the core flow with a supervised locker: sign in, list accessible
      compartments, request open, observe one hardware pulse, and receive the
      live door-state update.
- [ ] Verify command deduplication by the approved non-actuating test method; do
      not intentionally cause a second physical pulse.
- [ ] Verify content notes, audit/event history, and admin operational views.

## 8. Mobile beta distribution

- [ ] Verify the candidate against the beta backend on physical iOS and Android
      devices before submission.
- [ ] Submit the approved `mobile-v*` build to TestFlight and the configured
      Android Beta/internal testing track from the release path.
- [ ] Confirm the Android artifact and track are appropriate for the named pilot;
      an internal APK is not automatically an external beta programme.
- [ ] Restrict access to the named pilot tester group.
- [ ] On both platforms verify sign-in, terms, compartment list, open request,
      live door state, content notes, session expiry, and displayed backend
      identity/version.
- [ ] Record store processing status and the known-good build that testers can
      use if distribution is paused.

## 9. Go/no-go and release notes

- [ ] Release notes state that this is a controlled pre-production beta and that
      no production deployment currently exists.
- [ ] Notes list all component tags/digests, schema and contract changes,
      PR #227 credential ordering, PR #228 Modbus acceptance evidence, known
      issues, upgrade steps, and rollback targets.
- [ ] The release owner reviews hard gates, soak evidence, backups, artifact
      evidence, and smoke-test results.
- [ ] Record one decision: **continue**, **pause**, or **roll back**, with owner,
      time, rationale, and affected versions.

## 10. Post-release monitoring

- [ ] Monitor API errors and latency, queue failures/depth, MQTT auth/ACL denials,
      reconnects, duplicate commands, Reverb delivery, and database health.
- [ ] Monitor Pi CPU, memory, disk, container restarts, MQTT stability, Modbus
      reconnect cycles, heartbeat freshness, and relay/door anomalies.
- [ ] Monitor mobile crashes, sign-in failures, open-flow failures, and tester
      feedback on both platforms.
- [ ] Check at the agreed early interval, after 24 hours, and at the end of the
      pilot observation window.
- [ ] Stop expansion immediately for a security boundary failure, data
      corruption, unexpected door actuation, repeated stuck relay, credential
      cross-access, or loss of the core open/status flow.
- [ ] Close the pilot only after monitoring evidence and follow-up actions are
      recorded; otherwise keep it paused or execute the rollback runbook.
