# ADR-0050: Per-provisioning MQTT credential identities

## Status

Accepted

## Date

2026-08-22

## Context

A locker bank's MQTT username is its own UUID. `MqttReactor` mints credentials
with `$mqttUser = $event->lockerBankUuid`, and `MqttUserService::createUser()`
upserts on that username with `firstOrNew(['username' => …])`. Resetting a bank
deletes the row (`deleteUser((string) $lockerBank->id)`) and the next
provisioning recreates the same username with a new password.

That recreation is the problem. Deleting the row revokes access — with
`auth_opt_cache false` every later ACL check reaches Laravel and is denied. But
when the *same username* is created again for the next generation, an old
session that is still connected starts passing ACL checks again, without ever
having authenticated with the new password. The credential lifecycle has no
boundary between generations.

The obvious fix is to evict the old session at the broker. Mosquitto has no
supported way to do that, so it would mean maintaining a custom plugin purely
for client eviction — a lot of machinery for one edge.

The authorisation path is also coupled to the username's meaning. The device
branch of the ACL endpoint matches `locker/%u/state/#`, `locker/%u/response`,
`locker/%u/event` and `locker/%u/command`, where `%u` is the authenticated
username. The rule "you may touch your own locker's topics" is expressed as
"your username *is* the locker UUID" — an assumption nothing enforces.

Two things already in the codebase shape the answer:

- **A generation identifier exists.** ADR-0048 added
  `locker_banks.provisioning_generation`, and `MqttReactor::lockCurrentGeneration()`
  already compares it with `hash_equals` to discard stale provisioning events.
- **Disabling an identity is already honoured.** `mqtt_users.enabled` exists and
  *both* the auth path and the ACL path already filter `->where('enabled', true)`,
  and `username` already carries a unique index.

## Decision

**1. Every accepted provisioning issues a new, opaque MQTT username.**

The username is 32 characters of `Str::random()`, carrying no structure. It is
not derived from the locker UUID, the provisioning generation, or anything else
a reader could parse.

Opaque rather than `<locker-uuid>:<random>`: a parseable prefix invites exactly
the coupling this ADR removes — the first `explode(':')` in an ACL path puts the
assumption back, and nothing would fail loudly when it does. Operators correlate
identity to bank through `mqtt_users.locker_bank_id`, which is what the mapping
is for.

**2. Authorisation maps identity → locker bank; it never reads the username.**

The device branch of the ACL endpoint loads the enabled `MqttUser` (it already
does), takes `$user->locker_bank_id`, and matches topics against that UUID as a
literal. `%u` disappears from the device patterns.

Deny when the identity is missing, disabled, or has no locker-bank mapping.

The mapped UUID is matched the same way `%u` is, not by string-building a
pattern. `MqttAclService` gains a `%l` placeholder that compares one topic level
against a value passed alongside the username and client id, so the injection
safety of `%u` — a literal comparison, never a wildcard — extends to the mapped
locker UUID unchanged. Interpolating the UUID into the pattern would work today
and become an injection hole the moment anything but a UUID reached it.
`%u`/`%c` keep their current meaning for the provisioning and backend users.

The topic structure is unchanged: `locker/{lockerUuid}/…`. Only the
authenticated username stops being that UUID.

Re-provisioning therefore grants exactly the access the previous generation had.
Nothing bank-scoped lives on the identity — the row holds only credentials and
the mapping — so a new identity pointing at the same `locker_bank_id` is
equivalent by construction, with nothing to copy across.

**3. Revoked identities are disabled, never deleted, and never reused.**

Revocation sets `enabled = false` and stamps `revoked_at`. The row stays, so the
unique index on `username` keeps that identity permanently unusable.
`MqttUserService::deleteUser()` is removed along with its only caller — nothing
in the provisioning path may delete an identity, because the reuse guarantee
rests entirely on rows never disappearing.

`revoked_at` records when, not by whom. Every revocation traces back to a
provisioning reset — either directly, or through the issuance that reset led to
— and that reset is already attributed in the event stream through
`LockerProvisioningReset`. The row is a tombstone holding the username occupied,
not an audit record in its own right.

One exception is accepted: `mqtt_users.locker_bank_id` is
`constrained()->cascadeOnDelete()`, so deleting a locker bank still removes its
identities. That is harmless — a deleted bank has no successor generation to
protect, and a bank recreated later is a new UUID with new opaque identities.
Reuse only needs preventing while the bank it maps to still exists.

Both the auth and ACL paths already require `enabled = true`, so revocation
takes effect with no new checks. Revocation is by locker bank — every enabled
identity for that `locker_bank_id` is disabled — rather than by username, which
also covers legacy rows without special-casing them.

Revocation runs **at issuance as well as at reset**, inside the transaction that
inserts the new identity: disable every enabled identity for the bank, then
insert. "A bank has at most one live identity" is therefore an invariant of the
issuing path, not a property that holds as long as the reset path was reached
first. Reset-time revocation becomes the same rule applied with no successor.

#161's migration notes say the legacy identity is *deleted* on first reset. That
predates this decision: the same issue asks for the deletion-versus-tombstone
call to be made here, and it is made the other way. Legacy identities are
disabled like any other.

**4. Provisioning generation stays the concurrency boundary; the username is
not a generation marker.**

Repeated and concurrent provisioning attempts are already settled by
`lockCurrentGeneration()`: a `LockerWasProvisioned` event whose generation no
longer matches the row is stale and creates nothing. Identity creation stays
inside that same locked transaction.

Retries need more than that. `MqttReactor` is queued and deliberately rethrows
to trigger the retry strategy, so a failure while publishing the reply re-runs
the handler for the *same* generation — which `lockCurrentGeneration()` accepts,
because nothing has moved. Today `createUser()`'s upsert makes that idempotent;
an insert with a fresh random username would instead mint a second live identity
per attempt.

Decision 3's revoke-before-insert is what keeps this correct: a retry disables
the identity its predecessor created and issues one more, so however many
attempts occur, exactly one identity is live and it is the one whose credentials
were last published.

One tail remains, and it is not new. If an earlier attempt's reply reached the
client but its transaction then failed, the retry revokes the credentials that
client is holding — and if the replacement reply never reaches it, it is left
authenticating with a disabled identity until someone re-provisions the bank.
That is the same place a failed provisioning leaves a client today, and the
denial logging in decision 6 is how it surfaces.

No generation identifier is added to MQTT payloads. Unique authenticated
identities plus ACL mapping already make a previous generation unable to act,
which is what a payload marker would have been protecting against. Adding one
would put a second, weaker check in front of an authorisation boundary that
already holds.

**5. The provisioning reply carries the locker UUID as its own field.**

`ProvisioningReplyPublisher::publishSuccess()` publishes `mqtt_user` and
`mqtt_password`; it gains `locker_uuid`. The AsyncAPI contract and its schemas
are updated to match.

The client stores the two separately: the username is used only to authenticate
to the broker, and the locker UUID builds every topic — command, state, event,
heartbeat, response, and the Last Will registered at connect time. The Last Will
is set before the client has any reason to touch its username, which is exactly
where the old coupling is easiest to leave behind by accident.

**6. Retained state survives a generation change; in-flight command traffic does
not.**

Retained messages on `locker/{lockerUuid}/state/…` describe the physical bank,
not the identity that published them, so they are left in place and the newly
provisioned client republishes its snapshot on connect.

Command responses from a previous generation are not honoured: their
transaction ids belong to commands the backend no longer has outstanding, and
the existing dedup path already discards them. Nothing is added for this.

Commands queued for a previous generation's session are not delivered to the new
one: a new identity means a new broker session, so a queued persistent session
belongs to the old client and its queue drains — or expires — without the new
client ever subscribing to it. Any command that mattered is reissued by the
backend, which is how an unacknowledged command is already handled.

A revoked client that is still running keeps reconnecting and failing. Its
authentication is refused outright, and any ACL check it attempts is logged as a
denial on the broker channel — the same line the ACL path already writes — so
the condition is visible without new instrumentation. It is expected: an
identity is only revoked because that bank has been reset, and the client is
meant to be re-provisioned. Retained `state/connection` or Last Will messages
published by the old session before revocation stay on the topic and are
overwritten by the new client's snapshot on connect.

**7. Legacy UUID usernames keep working until their bank is re-provisioned.**

They need no special case. A legacy row already has `username = <locker uuid>`
*and* `locker_bank_id` set, so decision 2's mapping authorises it exactly as
before. On the first reset the legacy identity is disabled with the rest, and
the bank receives an opaque one.

Deployed clients keep running unchanged. A client that has not been updated
must not receive a new identity, so the updated client ships before any bank is
re-provisioned.

**8. The broker is pinned, and the assumption is tested against it.**

`iegomez/mosquitto-go-auth:latest` becomes `2.1.0-mosquitto_2.0.15` in both
compose files — the build that was already running, so pinning changes nothing
but what a later `docker pull` may hand back.

`locker-client/tests/integration/broker-revocation.test.ts` asserts the sequence
against that broker: a live identity publishes, its identity is revoked while a
new one is issued for the same bank, and the same still-connected session is then
denied. It is verified: with `auth_opt_cache false`, denial takes effect on the
next operation, without the session being evicted. The test needs the stack
running, so it is opt-in behind `OPEN_LOCKER_BROKER_TEST=1` and skipped in the
normal suite; it is the reason the image is pinned, since an upgrade to either
component can change this behaviour.

## Rationale

The security property comes from arithmetic rather than from timing: a revoked
username is never issued again, so there is no moment at which an old session's
username becomes authorised. Session eviction becomes a cleanup nicety instead
of a correctness requirement, and no custom broker plugin is needed.

Mapping through `locker_bank_id` makes the authorisation rule explicit. Today
the rule is enforced by a coincidence of naming; afterwards it is a lookup that
fails closed when the mapping is missing.

Disabling rather than deleting keeps the unique index doing the reuse
prevention. A deleted row frees its username again, which is the one thing this
ADR must never allow — which is also why the provisioning path loses its ability
to delete an identity at all.

## Alternatives Considered

### Alternative A: Keep UUID usernames and evict sessions at the broker

- Pros: no contract change, no client change, no migration.
- Cons: Mosquitto offers no supported eviction, so it means maintaining a custom
  plugin; and correctness would depend on the kick actually landing.
- Why not chosen: a permanent maintenance burden to patch a hole that unique
  identities close by construction.

### Alternative B: `<locker-uuid>:<random>` usernames

- Pros: an operator reading a broker log sees which bank an identity belongs to.
- Cons: parseable structure invites code to parse it, restoring the coupling
  this ADR removes — silently, since it would keep working.
- Why not chosen: the mapping already answers "which bank", and the prefix's
  only real benefit is convenience in a log line.

### Alternative C: Reuse `provisioning_generation` as the username

- Pros: unique per generation already, no new value to mint.
- Cons: conflates an authentication credential with an identifier used in events
  and reasoning about staleness; rotating credentials would then require a new
  provisioning generation.
- Why not chosen: two concerns that change for different reasons should not
  share a value.

### Alternative D: Hard-delete revoked identities

- Pros: no dormant rows; nothing to filter.
- Cons: frees the username for reuse, which is the exact failure being fixed.
- Why not chosen: reuse prevention is the point.

### Alternative E: Add a generation id to MQTT payloads

- Pros: a second signal that a message belongs to the current generation.
- Cons: a weaker check in front of an authorisation boundary that already holds;
  more contract surface and more to keep in sync.
- Why not chosen: unique identities plus ACL mapping already make a stale
  generation unable to publish at all.

## Consequences

### Positive

- A revoked identity can never be resurrected, so a still-connected old session
  cannot regain authorisation.
- No custom Mosquitto plugin, and no dependence on eviction landing.
- The ACL's locker-scoping becomes an explicit mapping that fails closed.
- Credentials can be rotated by re-provisioning, without a schema change.

### Negative

- The provisioning reply grows a field, so the contract and both sides move
  together.
- `mqtt_users` accumulates disabled rows, one per generation. They are small,
  and they are what holds each retired username permanently occupied.
- A revoked client that is still running reconnects and fails until someone
  re-provisions it, writing a denial line each time.
- An operator reading a broker log sees an opaque username and needs the mapping
  to know which bank it is.

### Risks

- **The core assumption is version-dependent.** That an already-connected
  client's later ACL checks are denied once its identity is disabled holds for
  the pinned broker with `auth_opt_cache false`, and is asserted by the
  integration test in decision 8. Upgrading Mosquitto or go-auth can change it,
  which is what the pin and that test are guarding.
- **A client updated after receiving a new identity** would authenticate with an
  opaque username while still deriving topics from it, and would be denied.
  Mitigated by shipping the client change before any re-provisioning.
- **Revocation by locker bank** disables every identity for that bank. That is
  the invariant rather than an assumption — decision 3 revokes before every
  insert — so a bank that legitimately needs two live identities would have to
  revisit this ADR, not work around it.

## Rollout / Migration

The order matters: no bank may be re-provisioned until a client that understands
the new reply is deployed. Opaque usernames are therefore issued **last**, and
steps 3–5 must reach production together if they are not released in sequence.

1. Add `revoked_at` to `mqtt_users` (nullable timestamp).
2. Map the ACL device branch through `locker_bank_id` via the `%l` placeholder;
   add cross-locker and wildcard-injection coverage. Legacy usernames keep
   working from this step on, and nothing else changes yet.
3. Add `locker_uuid` to the provisioning reply and update the AsyncAPI schemas.
   Existing clients ignore the new field.
4. Ship the locker-client change. The live coupling is
   `bootstrap/createApp.ts` (`const lockerUuid = credentials.username.trim()`)
   and the same line in `bootstrap/createSimulatorApp.ts`; the persisted schema
   in `adapters/persistence/file-credential.store.ts` is `{username, password}`
   and gains an optional `lockerUuid` that defaults to `username` when absent,
   which migrates existing credential files without touching them. The simulator
   is in scope — it reads the same store and builds the same topics.
5. Only once clients are updated: issue opaque usernames in `MqttReactor`,
   revoking every enabled identity for the bank inside the same locked
   transaction that inserts the new one — the retry path depends on it. Revoke
   likewise in `LockerProvisioningService` at reset, turn `createUser()`'s upsert
   into an insert, and remove `deleteUser()`. Cover a retried
   `LockerWasProvisioned` explicitly: it must leave one live identity, not two.

   Restart the queue workers as part of deploying this step. Issuance runs in a
   queued reactor, so workers that were already running keep executing the old
   code and quietly keep minting locker-uuid usernames — the provisioning
   succeeds, which is what makes it easy to miss.
6. Pin the broker image in both compose files; add the real-broker integration
   test.

Acceptance also covers #161's remaining cases: legacy credentials still
authenticate and reach only their own topics, re-provisioning yields a different
username and password, a disabled identity fails both auth and ACL, a new
identity reaches only topics mapped to its `locker_bank_id`, the client persists
both values and addresses topics by UUID, and an existing credential file
migrates.

Existing deployments need no action: legacy identities keep working until their
bank is next reset.

## Supersedes / Superseded By

- Supersedes: none.
- Related: [ADR-0048](0048-one-time-hmac-locker-provisioning.md) — provisioning
  generations and the reset flow this builds on.

## References

- Related issues: #161
- Code: `app/Http/Controllers/Mqtt/MosquittoAuthController.php`,
  `app/Services/MqttAclService.php`, `app/Services/MqttUserService.php`,
  `app/Reactors/MqttReactor.php`,
  `app/Mqtt/Publishers/ProvisioningReplyPublisher.php`
