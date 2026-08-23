# Beta Rollback Runbook

This runbook applies to the controlled pre-production beta pilot. Open Locker
does not currently have a production deployment. The policy behind this runbook
is proposed in
[ADR-0052](../adr/0052-controlled-beta-release-and-rollback.md).

## Before rollout

- Name one release owner with authority to continue, pause, or roll back, plus
  an operator for each affected component.
- Record the deployed `backend-v*`, `client-v*`, and `mobile-v*` tags and the
  intended rollback tags. Never use `latest` as either release evidence or a
  rollback target.
- Take a database backup, record its timestamp, and verify that it can be read.
- Review every migration for backward compatibility with the rollback backend.
  If old code cannot run against the migrated schema, require a forward fix or a
  tested migration-down/restore plan before proceeding.
- Back up each Pi's operator-managed configuration and persistent `/data`
  directory. Do not delete credential, client-ID, runtime-overlay, or dedup state
  as a routine rollback action.
- Define the smoke-test evidence, observation window, and decision time in the
  [beta release checklist](../release-checklist.md).

## Decision point

The release owner chooses one of three outcomes after the deployment smoke tests:

1. **Continue** when the API, MQTTS, Modbus, mobile, and core locker-open flow
   pass and no stop condition is active.
2. **Pause** when impact is contained and more evidence can be gathered without
   exposing additional testers or lockers.
3. **Roll back** when security, data integrity, credential isolation, physical
   lock safety, or the core open/status flow fails.

Record the decision, owner, time, failing check, affected component versions,
and selected recovery path.

## Backend rollback

1. Stop further rollout and suspend writes if migration or data integrity is in
   doubt.
2. Confirm whether the previous backend tag supports the current database
   schema.
3. If compatible, deploy the previous immutable `backend-v*` tag.
4. Restart the queue and event workers so no process continues running code from
   the failed release. Restart the MQTT listener or other long-running backend
   services when their image changed.
5. Run the API, worker, MQTT command/response, and admin smoke tests.
6. If the schema is incompatible, prefer a tested forward fix or reversible
   migration. Restore the database only as a last resort.

For a restore, the release owner must explicitly approve the data-loss window
from the backup timestamp through the write freeze. Keep writes stopped, archive
the failed database state for diagnosis, restore the verified backup, deploy its
compatible backend tag, restart workers, and repeat all smoke tests.

## Locker-client rollback

1. Stop automatic updates for the affected pilot Pi and pin the previous
   immutable `client-v*` image tag.
2. Preserve `config/` and `/data`; do not trigger re-provisioning merely to roll
   code back.
3. Redeploy the client and verify the running image/tag, MQTT connection,
   heartbeat, Modbus reachability, compartment snapshot, and one supervised
   open/door-state cycle.
4. Resume automatic updates only after the intended channel is known to point at
   an approved immutable version.

PR #227 constrains rollback as well as rollout: a compatible client must be
deployed before any bank receives a new opaque MQTT identity. Existing legacy
credentials remain valid until deliberate re-provisioning. Do not delete or
reuse revoked identities. If the backend issuance path was deployed, restart
queue workers after any backend version change and verify that one bank has at
most one enabled identity.

## MQTT edge and TLS rollback

1. Do not restore public plaintext port 1883 as a routine rollback. Keep the
   MQTTS endpoint and certificate trust boundary in place when rolling back only
   backend or client code.
2. If a Traefik or certificate change caused the incident, pause client rollout,
   restore the previous known-good edge configuration, and verify DNS, SNI, the
   certificate chain and hostname, ACME resolver state, and TCP routing from
   8883 to the private broker port 1883.
3. For Coolify, restore the previously recorded proxy configuration and
   Git-based Docker Compose resource revision. For standalone Compose, restore
   the previously tested Traefik overlay and pinned image configuration.
4. Use the temporary plaintext migration overlay only when it was already part
   of the approved migration window and the release owner explicitly accepts
   the exposure. Set its bind address explicitly, keep the provider firewall
   scope as narrow as the old clients permit, record the removal deadline, and
   remove it immediately after recovery.
5. From outside the host, repeat certificate verification and an authenticated,
   ACL-scoped MQTT round trip. Confirm port 1883 is unreachable before declaring
   rollback complete.

## Mobile rollback

- Stop or restrict TestFlight and Android beta distribution immediately.
- If the store still offers a known-good build to testers, direct the pilot
  group to that build and verify its backend compatibility.
- Installed mobile builds cannot be remotely replaced atomically. If downgrade
  is unavailable or unsafe, ship a forward fix under a new `mobile-v*` tag.
- Keep the backend compatible with the affected mobile versions during the
  transition; do not assume every tester updates at once.
- Repeat sign-in, compartment listing, open request, live door state, and content
  note checks on both iOS and Android before reopening distribution.

## Recovery verification

Do not declare rollback complete until all applicable checks pass:

- backend health and identity endpoints respond with the expected version;
- queue workers, event worker, scheduler, Reverb, and MQTT listener are healthy;
- an MQTTS client authenticates with the deployed trust configuration and
  plaintext MQTT is not accidentally accepted where the beta policy forbids it;
- the Pi reports online with Modbus reachable, publishes a compartment snapshot,
  and completes one supervised open/door-state cycle;
- revoked MQTT credentials are denied and current credentials remain scoped to
  their locker bank;
- TestFlight and Android beta builds complete the mobile core loop;
- logs show no repeated queue failures, reconnect loop, stuck relay, duplicate
  command execution, or unexpected credential issuance.

Continue heightened monitoring for the observation window recorded in the
checklist. The release owner closes the incident or keeps the pilot paused.
