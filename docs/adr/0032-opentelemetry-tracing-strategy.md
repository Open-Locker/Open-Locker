# ADR-0032: OpenTelemetry observability strategy across backend and locker client

## Status

Proposed

## Date

2026-07-27

## Context

An "open compartment" request crosses four processes before anything happens in
the physical world:

```
mobile app → HTTP request (Laravel) → queued reactor → MQTT publish
           → locker client (Pi) → Modbus write → relay
           → MQTT response/event → mqtt-listener → projector → read model
```

Each hop logs independently, and nothing ties those log lines together. When a
compartment does not open, the backend cannot distinguish between a command that
never reached the broker, a client that never answered, a Modbus write that
stalled, and a reactor that sat in the queue. All three failure modes look
identical from the API side: a request that simply does not complete.

The domain already records **what** happened, and records it well.
`CompartmentOpenRequestStatus` carries the lifecycle
(`requested → accepted → sent → acknowledged → opened`, plus `already_open`,
`door_jammed`, `failed`), and ADR-0031 added deviation events for outcomes that
were previously invisible. That layer is event sourced, permanent, and
authoritative.

What is missing is **where the time went and which hop broke** — the operational
view, which is timing and causality rather than domain fact. This ADR covers
that second layer only. It does not add, replace, or duplicate any domain event.

### Constraints

- No heavy observability stack in the default docker setup (issue #65). A
  collector is opt-in, never on by default.
- The backend's MQTT library, `php-mqtt/laravel-client`, speaks **MQTT 3.1 and
  3.1.1 only** (`vendor/php-mqtt/client/src/MqttClient.php:83`). MQTT 5 user
  properties — the conventional transport for trace context — are therefore
  unavailable, regardless of what the Node client supports.
- Any change to the external message contract requires AsyncAPI and JSON Schema
  updates plus contract tests (ADR-0015, ADR-0018).
- The locker client is hexagonal (ADR-0024). Domain and application layers must
  not depend on an observability vendor.
- Projectors run synchronously, reactors run queued (ADR-0028), so a trace must
  survive a process boundary to stay whole.

## Decision

### 1. Traces and logs, both components, phased delivery

Distributed **tracing** and **log shipping** are in scope. Metrics are not.

Traces were originally the only signal here, on the reasoning that logs already
work and tracing is what the system lacks. That was wrong against the actual
requirement: the operator's minimum is *"I can see logs and errors in a
dashboard, from the backend and the client"*, and traces alone do not deliver
it. An exception attached to a span is visible, but most of this system's error
reporting is `Log::warning` on a path that never throws — rejected payloads,
failed validation, unknown lockers, guard rejections. None of that surfaces in a
trace backend.

Both components therefore ship log records over OTLP as well, carrying the trace
id of the span they were written inside, so a log line links to the flow it
belongs to and vice versa. Logs keep going to their existing destinations too —
the file on the backend, the console on the Pi — because a collector outage must
never cost anyone their logs.

Volume is controlled the same way as for traces, by excluding the chatter rather
than sampling: broker authorization decisions log on every publish and every
subscribe, so they are routed to a channel that stays out of the telemetry
stack.

This ADR covers backend and locker client together, because the trace-context
field in the MQTT envelope (§4) is a contract both ends must agree on. It is
decided once, here. **Implementation is phased: Laravel first**, client second.

### 2. Backend: `keepsuit/laravel-opentelemetry` for the framework, manual spans for MQTT

The backend installs **`keepsuit/laravel-opentelemetry`**, which instruments
Laravel through the framework's own event and middleware hooks. It requires no
PHP extension: its `require` is `open-telemetry/{api,context,sdk,sem-conv,exporter-otlp}`,
`illuminate/*` and PHP ^8.2 — all satisfied by this backend today.

Out of the box it covers HTTP server requests, HTTP client calls, database
queries, Redis, cache, events, views and console commands. Two of its features
carry decisions in this ADR on their own:

- **Queued jobs** produce a `PRODUCER` span on dispatch and a `CONSUMER` span on
  execution, so a trace survives the reactor boundary (ADR-0028) with no
  dispatch code to modify.
- **`trace_id` is injected into log context automatically**, which is §9.

The MQTT layer has no automatic support under any package and is instrumented by
hand, using the package's `Tracer` facade:

| Site                                     | Span                                  |
| ---------------------------------------- | ------------------------------------- |
| `app/Mqtt/MqttPublisher`                 | one span per outbound publish         |
| `app/Mqtt/Handlers/*` (via `AbstractInboundMqttHandler`) | one span per inbound message |

Both sites are the single choke point of their direction — every publisher in
`app/Mqtt/Publishers/*` goes through `MqttPublisher`, and every handler through
`AbstractInboundMqttHandler` — so tracing and trace-context propagation are
impossible to forget when a new topic is added.

The package's one extension-dependent feature is Laravel Scout instrumentation.
This backend does not use Scout, so the `opentelemetry` PECL extension is not
installed anywhere.

### 3. Locker client: telemetry ports with one OpenTelemetry adapter

The client gets a `TracingPort` in `src/ports/` alongside the existing
`LoggerPort`, with a no-op default and an OpenTelemetry adapter under
`src/adapters/`. Application and domain code depends on the port only.

Spans are emitted for MQTT subscribe/publish and for Modbus operations. No
auto-instrumentation package is adopted: the client's meaningful surface is
`mqtt` and `modbus-serial`, neither of which has usable auto-instrumentation.

Log shipping sits behind a second port, `LogShippingPort`, implemented by the
same adapter — one endpoint, one lifecycle, one off switch — and fed by a winston
transport added next to the console one. The console transport stays: `docker
logs` on a Pi has to keep working when no collector is reachable, and losing the
local log because a network destination was configured would be a bad trade.

### 4. Trace context travels as a `traceparent` field in the MQTT payload

A W3C `traceparent` string is added as an **optional top-level property** of
every MQTT envelope: command, response, event, state, and the two provisioning
envelopes. The backend stamps it in `MqttPublisher`, which every outbound
publisher goes through, so covering provisioning as well is what the transport
actually does — and provisioning is a flow worth tracing in its own right.

```json
{
  "message_id": "...",
  "transaction_id": "...",
  "action": "open_compartment",
  "timestamp": "2026-07-27T12:04:31Z",
  "traceparent": "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01",
  "data": {}
}
```

It is **optional in every direction**. A message without it starts a new trace
rather than being rejected.

`traceparent` is transport metadata. It is never persisted as domain data, never
part of deduplication, and never used for business correlation — `message_id`
and `transaction_id` keep those roles exactly as ADR-0002 defines them.

### 5. Service identity

| Component     | `service.name`        | `service.instance.id` |
| ------------- | --------------------- | --------------------- |
| Laravel API   | `open-locker-backend` | hostname              |
| Locker client | `open-locker-client`  | locker UUID           |

Every Pi reports under one shared service name, distinguished by instance. Fleet
questions ("are all clients slow?") work by default; a single locker is a filter,
not a separate service.

### 6. Attribute naming: semantic conventions plus domain keys

Where an OpenTelemetry semantic convention exists it is used. Domain facts that
have no standard equivalent get project keys in dotted form:

| Fact               | Attribute                                     |
| ------------------ | --------------------------------------------- |
| MQTT topic         | `messaging.destination.name`                  |
| MQTT message id    | `messaging.message.id`                         |
| Messaging system   | `messaging.system` = `mqtt`                   |
| Business txn       | `open_locker.transaction_id`                  |
| Command id         | `open_locker.command_id`                      |
| Locker             | `open_locker.locker_uuid`                     |
| Compartment        | `open_locker.compartment_number`              |
| Outcome            | `open_locker.open_request_status`             |

The outcome attribute mirrors `CompartmentOpenRequestStatus` so operational
queries ("every jammed open last week, and how long each took") can be answered
from the trace backend. The event store remains the source of truth; span copies
are sampled and expire.

### 7. Sampling: 100%, reduced by exclusion rather than by ratio

The default sampler is `parentbased_always_on`. This is a small fleet with low
request volume, and #65 exists to trace *one* open flow end to end — a 10%
sample would most often discard the incident under investigation.

Volume is controlled by not instrumenting the periodic chatter:

- `LockerHeartbeatHandler` — no spans. Heartbeats arrive on a timer from every
  locker forever.
- `CompartmentSnapshotHandler` — no spans. Retained snapshots are re-delivered on
  every reconnect and published on every door-state change, making this the
  highest-volume inbound topic. The door-state facts stay visible as stored
  events; only their transport timing goes untraced.
- The client applies the same rule when **publishing** `state/*`, which is the
  other half of the decision and easy to overlook: excluding a topic only on
  receipt still leaves the sender emitting a span per heartbeat, and still puts
  a `traceparent` on the chattiest messages on the wire.
- HTTP health and broker-auth probes (`up`, `api/mosq/*`) — no spans, for the
  same reason.

Framework instrumentation for Laravel events, views and Livewire is also off:
this backend is event sourced, so every stored event fires framework events too,
and the admin panel's rendering is not part of the flows #65 exists to trace.

There is precedent for exactly this split: `AuditEventPresenter` already
classifies heartbeats and door-state changes as "high-volume telemetry events"
and excludes them from the audit log.

Console commands are not traced either. `keepsuit` treats its `commands` list as
an allow-list and ours is empty, which is deliberate rather than incidental:
`mqtt:listen` runs for the lifetime of the process, so a span around it would
never end, and every inbound message span would hang off that one root. Leaving
it out is what makes each inbound message its own trace — or a continuation of
the publisher's, per §4.

The sampler stays overridable through `OTEL_TRACES_SAMPLER_TYPE` and
`OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO`, so production can be dialled down
without a code change. Note these are `keepsuit`'s own config keys, not the
OpenTelemetry spec's `OTEL_TRACES_SAMPLER` / `OTEL_TRACES_SAMPLER_ARG`, which
this package does not read — setting the spec names has no effect.

### 8. Off unless an endpoint is configured

With no `OTEL_EXPORTER_OTLP_ENDPOINT`, or with `OTEL_SDK_DISABLED=true`, both
components record nothing and export nothing. The instrumentation ships enabled;
the destination is what gets switched on.

A collector is provided as an **opt-in overlay**,
`docker-compose.observability.yml`, with an example OTLP configuration. It is
never part of the default stack, and no specific vendor is adopted — SigNoz,
Tempo and Honeycomb are all reachable by changing the endpoint.

### 9. Log correlation and shipping

Backend log context gains `trace_id` automatically from
`keepsuit/laravel-opentelemetry`; spans started by hand call
`Tracer::updateLogContext()`. The client logger adds trace context itself. This
is the bridge from a log line to the trace it belongs to, and it works whether or
not an exporter is configured.

Shared log context outlives the span that set it, so the inbound MQTT handler
clears it once the message is handled. Without that, a long-running listener
stamps every later line — heartbeats included — with the trace id of whichever
message it happened to handle last, and following that id leads to an unrelated
incident. A wrong correlation is worse than none.

On top of correlation, both components **export** their log records over OTLP
(§1). On the backend this is an `otlp` channel added to the existing stack, never
replacing the file; on the client it is the winston transport described in §3.
Records written outside any span arrive with no trace id, which is correct —
they belong to no flow.

Broker authorization decisions are excluded, on the same volume reasoning as §7:
Mosquitto asks on every publish and every subscribe, and shipped to a dashboard
they would outnumber everything worth reading. They stay in the log file via a
dedicated `broker` channel.

### 10. No secrets on spans

Provisioning tokens, MQTT credentials and password material are never span
attributes. Whole payloads are never attached to spans; only the attributes in
§6 are.

Two consequences are load-bearing rather than aspirational:

- Registration topics embed the provisioning token in their path
  (`locker/register/{token}`), so the destination attribute **and the span name**
  use the template, never the token itself. A test asserts the token appears in
  neither.
- HTTP headers are recorded only when explicitly allow-listed, and the
  allow-list is empty, so `Authorization` and `Cookie` cannot reach a span.

## Rationale

**Why framework instrumentation plus manual spans, rather than one or the other.**
The MQTT layer is hand-written under either choice — the publishers, the
handlers, the `traceparent` field and every domain attribute are ours. Writing
everything by hand would not avoid that work; it would add HTTP and queue
instrumentation on top of it, including trace context across the queued-reactor
boundary, which is the one place a trace silently breaks (ADR-0028).

**Why `keepsuit/laravel-opentelemetry` rather than the official
`opentelemetry-auto-laravel`.** The SIG package is the more "official" route and
the one every vendor guide documents, but it declares `ext-opentelemetry` in its
`require`. Composer platform requirements are absolute, so that extension would
become mandatory in the Docker image, in CI, and in any environment that runs
`composer install` — a native extension compiled per PHP version, turning every
PHP upgrade into a two-part change.

`keepsuit` buys the same coverage through Laravel's own hooks with no extension:
HTTP, database, Redis, cache, events, and queue jobs with PRODUCER/CONSUMER
context propagation. Its only extension-dependent feature is Scout
instrumentation, and this backend has no Scout. It also injects `trace_id` into
log context on its own and ships a `null` exporter driver, so §8 and §9 become
configuration rather than code.

The cost is depending on a community package instead of the SIG one. At ~646k
installs with Laravel 13 already declared in its constraints, that risk is
acceptable, and §2 is deliberately the most replaceable decision here: swapping
back to the official package changes the bootstrap and nothing else.

**Why a payload field for `traceparent`.** MQTT 5 user properties are the natural
home for transport metadata, and the Node client supports them, but the backend's
PHP library implements only MQTT 3.1/3.1.1. Both ends must agree, so the ceiling
is set by the lower of the two. Making the field optional and additive keeps
mixed-version fleets working during rollout: all envelopes already declare
`additionalProperties: true`, and `InboundMqttProtocolGuard` validates only
`message_id` and `transaction_id`, so an added property changes no existing
behaviour.

**Why a port on the client.** ADR-0024 made the client hexagonal precisely so
that infrastructure choices stay at the edges. A tracing vendor imported into
`OpenCompartmentUseCase` would undo that. A port with a no-op default also keeps
the simulator and the test suite free of telemetry setup.

## Alternatives Considered

### Alternative A: Official `open-telemetry/opentelemetry-auto-laravel`

- Pros: Maintained by the OpenTelemetry PHP SIG; the route documented by every
  vendor guide, including the ones referenced in issue #65; zero-code
  instrumentation via the extension's hook mechanism.
- Cons: Hard-requires the `opentelemetry` PECL extension, which must then exist
  in the Docker image, in CI, and in every environment running
  `composer install` — it fails outright otherwise. The extension is compiled
  per PHP version, coupling telemetry to PHP upgrades.
- Why not chosen: `keepsuit` delivers equivalent coverage, including queue
  context propagation, with no extension and no infrastructure change. Remains
  the natural fallback if the community package stops tracking Laravel releases.

### Alternative B: Hand-written SDK instrumentation only

- Pros: No framework package at all; every span explicit; smallest dependency
  surface.
- Cons: HTTP and queue instrumentation written and maintained by hand against
  Laravel internals; trace context across queued jobs becomes our problem;
  log correlation must be built.
- Why not chosen: Materially more work for less coverage, and the MQTT spans —
  the part that genuinely must be hand-written — are unaffected either way.

### Alternative C: MQTT 5 user properties for `traceparent`

- Pros: Keeps transport metadata out of the business payload; the conventional
  approach.
- Cons: Impossible today — `php-mqtt/client` supports only MQTT 3.1/3.1.1.
- Why not chosen: Blocked by the library, not by preference. If the backend ever
  moves to an MQTT 5 client, this ADR should be superseded.

### Alternative D: `service.name` per locker

- Pros: A single Pi is trivially findable in any UI.
- Cons: One service entry per locker; fleet-wide comparison becomes manual.
- Why not chosen: `service.instance.id` provides the same filtering without
  fragmenting the service list.

### Alternative E: Ratio sampling (e.g. 10%)

- Pros: Lower storage and CPU as the fleet grows.
- Cons: The specific failed open under investigation is probably the sample that
  was dropped.
- Why not chosen: Defeats the stated goal of #65. Excluding heartbeats and
  no-op snapshots removes the volume without the blind spot, and the ratio stays
  configurable if scale changes the calculus.

### Alternative F: Adopt SigNoz (or another vendor) in the default stack

- Pros: Working dashboards immediately, no per-developer setup.
- Cons: A heavy stack in everyone's docker compose for a capability most runs
  never use.
- Why not chosen: Explicitly excluded by issue #65.

## Consequences

### Positive

- One open flow is traceable end to end, across four processes, with per-hop
  timings.
- "It didn't open" becomes answerable: which hop stalled, and for how long.
- Vendor-neutral — pointing at a different backend is one environment variable.
- Default developer and production setups are unchanged until someone opts in.
- Log lines carry `trace_id`, so an existing log-first workflow still benefits.

### Negative

- The MQTT contract gains a property, with the AsyncAPI, JSON Schema and
  contract-test work that implies.
- Two instrumentation styles on the backend (package-provided for the framework,
  manual for MQTT) — contributors must know which applies where.
- A community package sits on the critical path of backend observability.
- The client gains a port and an adapter to maintain.

### Risks

- **Long-running processes.** `MqttListen` and queue workers run for days.
  Unflushed spans accumulate in memory. This one bit during Phase 4 rather than
  staying theoretical: the package only recognises Octane and queue workers as
  worker modes, so `mqtt:listen` triggered no periodic flush at all and its
  spans never reached the collector — the trace simply ended at the client's
  reply. Mitigation: the inbound handler flushes explicitly after each traced
  message. Only responses, events and registrations get that far, so the cost is
  bounded by design, and memory is still watched during the first soak (#169).

  Queue workers have the same shape for a different reason: they *are* detected
  as a worker mode, but `flush_after_each_iteration` defaults to false, so a job
  that throws exports nothing and the failure is invisible — the trace stops
  after the job was queued and no error is recorded anywhere. Mitigation:
  `OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION=true`, set in the overlay and
  documented for every other deployment. Both halves of this risk were found by
  running the system, not by the test suite.
- **Community package maintenance.** `keepsuit/laravel-opentelemetry` is not
  maintained by the OpenTelemetry SIG. If it lags a future Laravel release it
  blocks the framework upgrade. Mitigation: it is the most replaceable decision
  here — Alternative A swaps in by changing the bootstrap, at the cost of adding
  the PECL extension to the image.
- **Instrumentation coverage differs from the official package.** Hook-based
  instrumentation sees what Laravel emits events for, not everything the
  extension can intercept. Mitigation: the spans this ADR actually needs — HTTP,
  queue, MQTT — are all covered, confirmed in Phase 1 before anything was built
  on top. The queued-reactor hop is the one that would break silently, so it is
  held by a regression test that dispatches over a real queue connection and
  runs an actual worker rather than the sync driver, which would hide a break.
- **Payload growth.** `traceparent` adds ~55 bytes to every message. Negligible
  for commands; measurable only on high-frequency state topics, which are
  excluded from tracing anyway.
- **Attribute drift.** Domain outcome copied onto spans can diverge from the
  event store if the enum changes. Mitigation: the attribute is written from the
  enum, never hand-typed.

## Rollout / Migration

**Phase 1 — Laravel** (per issue #65: *"first start with laravel"*)

1. `composer require keepsuit/laravel-opentelemetry`; publish and configure
   `config/opentelemetry.php` — resource attributes per §5, OTLP exporter from
   env, `null` driver when unconfigured. No image or CI change.
2. Confirm the package's HTTP and queue spans appear and that a queued reactor
   lands under the originating request, before building further on it.
3. Manual spans in `MqttPublisher` and `AbstractInboundMqttHandler`, with the §6
   attributes; heartbeat and no-op snapshot handlers excluded.
4. `Tracer::updateLogContext()` on hand-started spans so §9 holds outside the
   package's own instrumentation.
5. Inject `traceparent` on outbound publishes; read it on inbound.

**Phase 2 — contract**

1. Add the optional `traceparent` property to the envelope schemas as
   `schemas/common/traceparent.json`. `docs/asyncapi/mqtt.yaml` needs no edit of
   its own: it `$ref`s the envelope and payload schemas, and the payload schemas
   `allOf` their envelope, so the property propagates everywhere from one file.
   Every envelope already sets `additionalProperties: true`, which is why the
   backend can stamp the field before the client understands it, and why the
   client's zod schemas strip it rather than rejecting the message.

**Phase 3 — locker client**

1. `TracingPort` plus OpenTelemetry adapter and no-op default; spans for MQTT
   publish/subscribe and Modbus operations; continue the trace from an inbound
   `traceparent` and emit it on responses and events.
2. `LogShippingPort` on the same adapter, fed by a winston transport, so the
   client's logs and errors reach the dashboard as well as the console.
3. The fleet simulator is wired the same way, so a full flow can be demonstrated
   without hardware.

**Phase 4 — collector and docs**

1. `locker-backend/docker-compose.observability.yml`, an opt-in overlay adding an
   OTLP collector (`docker/otel-collector.yml`) and pointing every PHP process at
   it. No trace UI ships in the overlay: the collector forwards to whichever
   backend the operator runs as its own Compose project, which keeps that choice
   out of this repo entirely and keeps #65's "nothing heavy in the default
   stack" trivially true. The collector publishes its OTLP ports on the host so
   a Pi outside Compose can reach it, and those ports are overridable because
   most trace backends want the same 4317/4318.
2. A `logs` pipeline in the collector alongside `traces`. Without it the
   collector accepts log records and silently drops them.
3. `docs/observability.md`: required env vars for both components, running with
   and without a collector, the expected span tree for one open flow, how to
   read it when a hop is missing, the attributes worth filtering on, and where
   logs and exceptions appear.
4. Switching backends is a change to the collector config alone — Tempo,
   Honeycomb and Jaeger examples ship commented in that file, and neither the
   backend nor the Pis are redeployed to move telemetry elsewhere.

**Fallback.** Every piece is additive and inert by default. If tracing proves
disruptive, unset the exporter endpoint and the system behaves exactly as it does
today. If `keepsuit` proves inadequate or unmaintained, Alternative A replaces §2
— adding the PECL extension to the image and CI — without affecting §3–§10.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: —
- Related issues: #65, #173 (locker-client protocol errors and observability)
- Related docs:
  - ADR-0002 (message-id and transaction-id separation)
  - ADR-0015 (MQTT contract via AsyncAPI and JSON Schemas)
  - ADR-0018 (contract validation through component test suites)
  - ADR-0024 (locker-client v2 hexagonal rewrite)
  - ADR-0026 (admin audit log)
  - ADR-0028 (synchronous projectors, queued reactors)
  - ADR-0030 (batched door polling and change-only snapshots)
  - ADR-0031 (separate command acknowledgement from door-open detection)
- External:
  - `keepsuit/laravel-opentelemetry` —
    https://github.com/keepsuit/laravel-opentelemetry
  - OpenTelemetry Laravel instrumentation (Alternative A) —
    https://signoz.io/docs/instrumentation/opentelemetry-laravel
  - OpenTelemetry Node.js instrumentation —
    https://signoz.io/docs/instrumentation/javascript/opentelemetry-nodejs/
  - W3C Trace Context — https://www.w3.org/TR/trace-context/
