# ADR-0033: Harden locker-client local persistence

## Status

Accepted

## Date

2026-08-11

## Context

The locker client keeps four persistent files in `DATA_DIR`:

- `.mqtt-client-id` preserves the MQTT session identity.
- `.mqtt-credentials.json` contains the provisioned MQTT username and password.
- `.runtime-config-overlay.json` contains the backend-managed physical compartment
  mapping and heartbeat interval.
- `.mqtt-dedup-state.json` contains seen MQTT message identifiers, command
  execution records, final responses, and response-delivery timestamps.

These files previously used independent direct writes. A crash or storage error
could therefore expose a partial JSON document. File modes were not consistently
restricted, malformed credentials could be mistaken for an unprovisioned device,
and deduplication data had no retention policy. ADR-0032 deliberately deferred
atomic persistence and bounded retention to issue #168.

The runtime overlay determines which physical relay a command can address.
Silently discarding a malformed overlay could therefore change physical behavior.
Deduplication and response state also protects against repeating a physical
operation whose outcome is already known or uncertain.

Atomic replacement protects individual writes but does not serialize multiple
locker-client processes sharing one `DATA_DIR`. Concurrent writers could each
hold stale in-memory state and overwrite the other's newer state.

## Decision

1. All locker-client files under `DATA_DIR` use one dependency-free synchronous
   persistence utility. It creates a unique temporary file in the destination
   directory, completes and flushes the write, applies the requested mode, and
   atomically renames the temporary file over the destination. Temporary files
   are removed after pre-rename failures, and the previous destination remains
   unchanged. The containing directory is flushed where the filesystem supports
   it.
2. Newly created data directories use mode `0700`. All four persistent files use
   mode `0600`. Existing credential files are hardened to `0600` before they are
   read.
3. Persistent corruption is fail-closed and is reported with
   `PersistentStateCorruptedError`. Error messages identify only the state type
   and file path; they never include credentials, payloads, or persisted content.
4. Existing but malformed or incomplete credentials block startup and remain
   untouched. Only an absent credential file starts provisioning.
5. A malformed, empty, or unsupported runtime overlay blocks startup and remains
   untouched. The client never falls back to an empty physical mapping when an
   overlay file exists. This supersedes only ADR-0009's permissive corruption
   fallback; its base-config/runtime-overlay ownership decision remains accepted.
6. Malformed deduplication, command, response, or delivery state blocks startup
   before MQTT command processing begins and remains untouched.
7. An absent client-ID file creates a new random identity atomically. An existing
   empty or syntactically invalid client-ID file blocks startup and remains
   untouched. An operator can recover explicitly by restoring a valid identifier,
   configuring `MQTT_CLIENT_ID`, or deleting the invalid file to request a new
   identity. Deletion intentionally abandons the previous MQTT session.
8. Dedup state format version 2 adds a top-level `version` marker and normalizes
   command records. A transaction ID exists only as the `commandRecords` map key,
   and the action exists only on the command record. The stored response contains
   only response-specific fields such as `result`, `message`, `error_code`, and
   `applied_config_hash`; the dispatcher reconstructs the complete MQTT response
   from the map key, record action, and stored response.
   Legacy unversioned files are validated, pruned, and rewritten atomically.
   Their full responses are normalized only when their `transaction_id` and
   `action` exactly match the enclosing map key and command record. A mismatch is
   corruption and blocks startup.
9. Seen message identifiers are retained for 30 days and capped at 10,000.
   Pruning orders entries by timestamp and then identifier, retaining the newest
   entries deterministically.
10. Version 2 requires `in_progress` records to have no final response or delivery
    timestamp, and ordinary `completed` records to have a final response. Legacy
    completed records that predate response persistence are migrated with an
    explicit `legacyResponseUnavailable: true` marker; they have neither a
    response nor a delivery timestamp and continue to suppress physical
    execution.
11. Completed command records with a stored response and a delivery timestamp are
    retained for 30 days after delivery. A completed response without a delivery
    timestamp is never pruned. Marked legacy completed records without a response
    are never pruned because delivery is unknown. `in_progress` records and
    recovered unknown-outcome records are never pruned until ADR-0032 has
    finalized and delivered their response. Migration and pruning never invoke a
    command handler.
12. The MQTT topics, payload schemas, and ADR-0032 recovery behavior do not
    change. This decision does not implement general age-based command rejection.
13. The production Docker entrypoint permits one writer per
    `${DATA_DIR:-/data}` by acquiring an exclusive, nonblocking util-linux
    `flock` on `.locker-client.lock`. `--no-fork` makes the lock-owning process
    the client itself, preserving direct signal delivery and holding the lock for
    the client's lifetime. Lock contention fails closed with exit code `75`.
    The kernel releases the lock on normal exit, crash, `SIGKILL`, or container
    restart; the lock file may remain and is not stale-lock evidence.
14. This single-writer guarantee assumes a local Linux filesystem. Lock
    semantics on NFS and CIFS are not guaranteed, and starting Node directly
    outside the production entrypoint bypasses the lock.
15. The production image remains root-based for now. Raspberry Pi serial devices
    commonly use a host-specific supplementary group, and the current Compose
    deployment has no safe, portable group-ID mapping. Choosing a fixed group or
    broadening device permissions would either break hardware access or weaken
    host security. `/config` remains read-only and `/data` writable. A separate
    deployment change must introduce an explicit site-provided serial GID and
    matching volume ownership before changing the image user.

## Alternatives Considered

### Continue direct writes and recover malformed files automatically

- Pros: Minimal implementation and fewer startup failures.
- Cons: A crash can lose the old and new state; automatic recovery can repeat a
  physical operation or discard a valid physical mapping.
- Why not chosen: Availability does not outweigh credential, state, and physical
  safety.

### Use a database or third-party atomic-file package

- Pros: Mature transactions or less custom filesystem code.
- Cons: Adds runtime and operational complexity for four small local files.
- Why not chosen: Same-directory temporary files plus atomic rename provide the
  required boundary without a new dependency.

### Prune all old completed and in-progress commands

- Pros: Places a strict upper bound on every part of the state file.
- Cons: Could discard an undelivered final response or permit physical
  re-execution after an unknown outcome.
- Why not chosen: Only acknowledged final records have a safe retention boundary.

### Run the production container as a fixed non-root user immediately

- Pros: Reduces privileges inside the container.
- Cons: Raspberry Pi serial group IDs vary, and bind-mounted `/data` ownership
  must match the selected runtime UID.
- Why not chosen: No portable mapping exists in the current deployment contract;
  guessing it would break serial access or require unsafe permissions.

## Consequences

### Positive

- Interrupted writes do not replace valid local state with partial content.
- Credentials and state are owner-readable only.
- Corruption stops command processing instead of silently weakening deduplication
  or changing physical mapping.
- Dedup growth is bounded where a safe deletion boundary exists.
- Existing ADR-0032 state migrates without repeating hardware operations.
- The production deployment prevents concurrent processes from overwriting each
  other's local state.

### Negative

- Corrupt files require explicit operator recovery.
- Pending, legacy, and unknown-outcome records can grow until their safe terminal
  state is known.
- The container still runs as root pending an explicit serial-group deployment
  contract.
- Direct Node starts and deployments on filesystems without reliable Linux
  `flock` semantics do not receive the single-writer guarantee.

### Risks

- Filesystems without directory `fsync` support have a weaker guarantee across
  sudden power loss after rename; the utility still flushes the file itself.
- Deleting a corrupt client-ID file starts a new MQTT session and can abandon
  broker-queued messages for the old identity. Operators must make that choice
  deliberately.
- Incorrect host ownership can make `/data` unwritable. Deployment instructions
  require private host directories and prohibit `chmod 777`.
- Lock contention stops the new process with exit code `75`; operators must
  resolve the concurrent deployment rather than delete the persistent lock file.

## Rollout / Migration

- Back up `/data` before upgrading.
- Ensure the host `data` directory is private and writable by the current
  container deployment.
- Keep production startup routed through the image entrypoint and use a local
  Linux bind mount for `/data`. A remaining `.locker-client.lock` requires no
  cleanup; verify whether a process holds it before diagnosing contention.
- On first access, valid unversioned dedup state is atomically rewritten as
  normalized version 2 and safe retention rules are applied. Full legacy
  responses are stripped of redundant identity fields after consistency
  validation; response-less completed records receive the explicit legacy
  marker.
- Existing credentials are changed to mode `0600` during startup.
- If startup reports persistent corruption, stop the service and inspect or
  restore that specific file. Do not replace dedup or overlay state with an empty
  file.
- Validate a future non-root rollout separately with the real serial-device group
  and explicit `/data` ownership before changing the image user.

## References

- GitHub issue #168
- GitHub issue #171
- `docs/adr/0009-locker-client-runtime-config-overlay.md`
- `docs/adr/0014-locker-client-mqtt-session-and-reconnect.md`
- `docs/adr/0032-locker-client-command-response-recovery.md`
- `locker-client/Dockerfile`
- `locker-client/docker-entrypoint.sh`
- `locker-client/src/infrastructure/file-persistence.ts`
- `locker-client/src/adapters/mqtt/dedup-store.ts`
