# ADR-0034: One-time HMAC locker provisioning

## Status

Accepted

## Date

2026-08-17

## Context

Locker-bank provisioning uses a random token in the MQTT topic
`locker/register/<token>`. The backend previously retained that credential in
plaintext on `locker_banks` and displayed it indefinitely in Filament. This made
database, backup, and admin-screen access sufficient to recover a live
provisioning credential.

Provisioning acceptance, administrative reset, projection updates, and queued
MQTT credential creation also need one concurrency boundary. A delayed
`LockerWasProvisioned` reactor job must not recreate credentials or publish a
password after an administrator has reset the bank or a later provisioning
generation has won.

The MQTT topic contract cannot change yet. Existing backend and locker-client
topic redaction therefore remains required. Issue #159 covers this at-rest and
one-time workflow. Per-generation MQTT usernames, active broker-session
eviction, and complete session revocation remain in issue #161.

## Decision

Use a dedicated `PROVISIONING_TOKEN_HMAC_KEY`, loaded through application
configuration and rejected at runtime unless it is a non-placeholder secret of
at least 32 characters. The key is independent of `APP_KEY`. Hash plaintext
provisioning tokens with HMAC-SHA-256 and store the 64-character lowercase
hexadecimal digest in nullable, unique
`locker_banks.provisioning_token_hmac`.

Add nullable UUID `locker_banks.provisioning_generation`. Remove
`locker_banks.provisioning_token` and automatic token generation from the model.
Existing unconsumed plaintext tokens are intentionally invalidated during this
pre-Beta migration. Already provisioned clients continue to use their existing
MQTT credentials.

A focused `LockerProvisioningService` owns both workflows:

1. Administrative restart requires a concrete `User` with
   `lockerbank.configure`.
2. It starts a database transaction, reloads and locks the locker-bank row, and
   only then generates the plaintext token, HMAC, and generation UUID.
3. It persists `LockerProvisioningReset` followed by
   `LockerProvisioningTokenIssued`. Synchronous projectors first clear all
   provisioning/device/config state and then project the HMAC and generation.
4. It deletes the UUID-named MQTT user in the same transaction and returns the
   plaintext only after commit.
5. Filament immediately chains to a non-persistent copy modal. The token is
   held only in a protected Livewire property for the response that renders the
   modal; it is not dehydrated into action arguments or the client-side
   Livewire snapshot. The token is not put in a notification, log, database,
   event, or session. Loss requires another restart.
6. Registration computes the HMAC from the topic token, finds and locks the row
   by HMAC in a transaction, and records `LockerWasProvisioned` with the current
   generation. Its synchronous projector sets `provisioned_at`, consumes the
   HMAC by setting it to `null`, and retains the generation.

`LockerProvisioningReset` clears HMAC, generation, `provisioned_at`, connection
status timestamps, heartbeat, and config sent/ack state.
`LockerProvisioningTokenIssued` contains only HMAC, generation, actor, and
timestamp, so the locker-bank provisioning read model is replayable without
plaintext. `LockerWasProvisioned` carries the generation used by the queued
reactor. Its stored-event creation metadata supplies the deterministic
`provisioned_at` value during replay. A nullable generation keeps older stored
events deserializable; such legacy events are never eligible for queued
credential creation.

Before both MQTT-user creation and credential publication, `MqttReactor` locks
and compares the current locker-bank generation with the event generation and
requires the bank to still be provisioned. The second check closes the reset
window between user creation and publication. Jobs stale after reset or a newer
generation exit without publishing, while reset transactionally deletes any
user created before it acquired the row lock. MQTT passwords remain transient
except for the existing one-way password hash in `mqtt_users`.

`mqtt_users` is an operational credential store, not an event-sourced read
model. Administrative deletion is deliberately performed by the service and is
not reconstructed by event replay. Replaying locker-bank events therefore
rebuilds provisioning state but does not create or delete broker credentials.

The MQTT topic and payload contract stays unchanged. Token-bearing registration
topics continue to be redacted in backend and locker-client logs.

## Alternatives Considered

### Encrypt and retain plaintext tokens

This would allow redisplay but preserve a recoverable long-lived credential and
introduce encryption-key rotation and access concerns. One-time display avoids
that requirement.

### Use `APP_KEY` for HMAC

This reduces configuration but couples provisioning-token verification to
framework encryption and application-key rotation. A dedicated key provides a
clearer boundary and independent rotation policy.

### Store only non-event-sourced HMAC state

This is simpler, but replay would lose whether a token is available and which
generation owns delayed work. HMAC and generation are safe to retain in events.

### Change MQTT usernames or evict broker sessions now

This would improve revocation but changes credential/session lifecycle beyond
this decision. It remains tracked by issue #161.

## Consequences

Plaintext provisioning credentials no longer survive the request that issues
them. Database and event-store disclosure cannot recover an available token.
Concurrent reset and registration operations serialize on the locker-bank row,
and queued provisioning work is generation-safe.

Operators must configure the dedicated HMAC key before issuing or accepting a
token. Losing the one-time token requires rotation. Rotating the HMAC key
invalidates all outstanding, unconsumed tokens. Existing MQTT sessions are not
forcibly disconnected, and UUID usernames can be recreated after provisioning;
issue #161 remains necessary for complete revocation.

### Rollout / Migration

1. Generate a dedicated key with `openssl rand -base64 48` and configure the
   same `PROVISIONING_TOKEN_HMAC_KEY` on every backend instance before
   deployment.
2. Deploy the schema migration that removes plaintext tokens and adds HMAC and
   generation columns. Outstanding old tokens become invalid.
3. Restart provisioning from Filament for every bank that still needs a token,
   copy the one-time value into the client's `PROVISIONING_TOKEN`, clear its
   local provisioning state, and restart it.
4. Keep existing provisioned clients running with their current MQTT
   credentials.
5. Keep registration-topic redaction and the unchanged MQTT contract until a
   separately agreed protocol migration.

## References

- Issue #110 — administrative locker provisioning restart
- Issue #159 — HMAC and one-time provisioning token storage
- Issue #161 — per-generation MQTT identities and session revocation
- ADR-0014 — locker-client MQTT session and reconnect
- ADR-0017 — split MQTT state topics by lifecycle
- ADR-0021 — role-based access control
- ADR-0026 — admin audit log
