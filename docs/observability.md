# Tracing an open flow

Opening a compartment crosses four processes:

```
mobile app → HTTP request (Laravel) → queued reactor → MQTT publish
           → locker client (Pi) → Modbus write → relay
           → MQTT response/event → mqtt-listener → projector → read model
```

Each of those logs on its own. Distributed tracing is what ties them into a
single timeline, so "the compartment did not open" becomes a question with an
answer: which hop stalled, and for how long.

Both **traces** and **logs** are shipped, from the backend and from every locker
client. Metrics are out of scope. Log records carry the `trace_id` of the span
they were written inside, so a log line in the dashboard links to the flow it
came from — and a slow trace links to everything it logged.

Logs also keep going where they always did: the log file on the backend, the
console on the Pi. The collector is an additional destination, never the only
one.

## Off unless you turn it on

Nothing is recorded or exported until an OTLP endpoint is configured. With no
endpoint:

- the backend records nothing and adds nothing to MQTT payloads;
- the locker client never even loads the OpenTelemetry SDK.

That applies to development and production alike. Turning tracing on is a
configuration change, not a deploy.

## The short way

From the repo root:

```bash
just trace-up        # SigNoz + the stack with tracing on
just trace-status    # is it actually wired up?
just trace-down      # back to a stack with no tracing
```

`trace-up` clones SigNoz to `~/.open-locker/signoz` on first run, publishes its
UI on **http://localhost:8085**, and brings our stack up with the overlay and
the moved OTLP ports. `SIGNOZ_DIR` and `SIGNOZ_UI_PORT` override both.

Bringing your own Jaeger, Tempo or Honeycomb instead? Point the collector at it
(see [below](#sending-traces-somewhere-else)) and use `just trace-overlay`,
which starts the stack with tracing on and no SigNoz.

`just trace-status` exists for one failure in particular: every container is up,
SigNoz answers, and nothing arrives — because the stack was last started without
the overlay, so the app has no `OTEL_*` env and exports nothing. It checks that
explicitly.

The rest of this page is what those recipes do, for when you need to do it by
hand or something misbehaves.

## Running with a collector

The stack ships an opt-in overlay that adds an OpenTelemetry Collector and
points every PHP process at it:

```bash
cd locker-backend
docker compose -f docker-compose.yml -f docker-compose.observability.yml up -d
```

The overlay is opt-in on purpose: a plain `sail up` or `docker compose up -d`
loads only `docker-compose.yml`, leaving the stack untraced. That also means it
is easy to forget — recreate the containers without both `-f` flags and tracing
silently stops, which is what `just trace-status` is for.

The overlay deliberately contains **no trace UI**. The collector forwards to
whichever backend you run, so the choice stays out of this repo and out of the
default stack. Out of the box it forwards to SigNoz on the host:

```bash
# SigNoz, as its own Compose project (not part of this repo)
git clone -b v0.99.0 --depth 1 https://github.com/SigNoz/signoz.git
cd signoz/deploy/docker && docker compose -p signoz up -d
```

Its UI is then at **http://localhost:8080**.

Clone it somewhere permanent. Its containers bind-mount their config out of the
checkout, so if the directory is deleted they cannot be started again — not even
by `docker start` — and have to be recreated from a fresh clone.

SigNoz publishes OTLP on 4317/4318, the same ports our collector publishes for
external clients, so start the overlay with those moved out of the way:

```bash
FORWARD_OTLP_HTTP_PORT=4418 FORWARD_OTLP_GRPC_PORT=4417 \
  docker compose -f docker-compose.yml -f docker-compose.observability.yml up -d
```

To stop tracing, bring the stack up without the overlay again. Nothing else
needs to change.

### Sending traces somewhere else

Both the backend and the locker clients only ever know about the collector, so
the destination is a change to `locker-backend/docker/otel-collector.yml` alone
— no application config, and no redeploy of the Pis. SigNoz is what that file
targets out of the box; it also carries worked examples for Grafana Tempo,
Jaeger and Honeycomb.

Nothing about the backend needs SigNoz specifically: it is simply the default
the collector points at. Bring the overlay up with no backend running and the
collector retries a refused connection and logs it — the application keeps
working, but nothing is stored.

## First run, step by step

What follows is the exact sequence that works, including the parts that bite.
Steps 1 and 2 are what `just trace-up` automates; do them by hand when you want
to see what it is doing, or when it does not work.

**1. Start a trace backend.** SigNoz, as its own Compose project:

```bash
git clone -b v0.99.0 --depth 1 https://github.com/SigNoz/signoz.git
cd signoz/deploy/docker && docker compose -p signoz up -d
```

Its UI wants host port 8080. If something already has it (a local Apache, for
instance) the `signoz` container fails to start and everything else looks fine.
Move it with an override file:

```yaml
# signoz-port-override.yml
services:
    signoz:
        ports: !override
            - "8085:8080"
```

```bash
docker compose -p signoz -f docker-compose.yaml -f signoz-port-override.yml up -d signoz
```

**2. Start our stack with the overlay**, with the collector's published ports
moved aside so they do not collide with SigNoz's 4317/4318:

```bash
cd locker-backend
FORWARD_OTLP_HTTP_PORT=4418 FORWARD_OTLP_GRPC_PORT=4417 \
  docker compose -f docker-compose.yml -f docker-compose.observability.yml up -d
```

**3. Connect a client.** The fleet simulator is enough, and needs the collector's
moved HTTP port:

```bash
cd locker-client
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4418 pnpm sim
```

**4. Open a compartment** — from the app, the admin panel, or the API. Use a
compartment on the bank the client is provisioned as, or the trace stops at the
publish because nothing is listening.

**5. Look.** SigNoz → Traces → last 15 minutes → operation
`POST /api/compartments/{compartment}/open`. Prefer a trace with a high span
count and two services; that is the complete round trip.

### When nothing shows up

- **SigNoz's own collector answers on 4318 but drops gRPC on 4317.** It only
  serves gRPC after its server hands it a configuration, and it does not always
  recover from starting before the server is ready. `docker restart
  signoz-otel-collector` fixes it, and the collector config here uses OTLP over
  **HTTP** for that reason.
- **Check our collector first**: `docker compose logs otel-collector`. The
  `debug` exporter prints every span it receives, so it tells you whether the
  problem is upstream (nothing arriving) or downstream (arriving but not
  forwarded).
- **Recreating a worker restarts the broker.** The workers `depend_on` `mqtt`,
  so `docker compose up -d event-worker` will quietly start a broker you had
  deliberately stopped.

## Environment variables

### Backend

| Variable                                | Meaning                                                              |
| --------------------------------------- | -------------------------------------------------------------------- |
| `OTEL_EXPORTER_OTLP_ENDPOINT`           | Collector base URL. **Unset means tracing is off.**                  |
| `OTEL_SERVICE_NAME`                     | Defaults to `open-locker-backend`.                                   |
| `OTEL_SERVICE_INSTANCE_ID`              | Which process this is (`app`, `event-worker`, …). Defaults to hostname. |
| `OTEL_SDK_DISABLED`                     | `true` switches everything off even with an endpoint set.            |
| `OTEL_TRACES_SAMPLER_TYPE`              | `always_on` (default), `always_off`, or `traceidratio`.              |
| `OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO`| Sampling ratio when using `traceidratio`.                            |
| `OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION` | **Set this to `true`.** See below.                             |

> Sampling defaults to `always_on`, which is right for development and for
> tracing a single open flow end to end — the reason this exists. It is the wrong
> default for a fleet: every span from every bank, kept. Before pointing a
> deployment at a collector, set `OTEL_TRACES_SAMPLER_TYPE=traceidratio` with a
> ratio, or accept the volume deliberately rather than by omission.

> Queue workers batch their spans and only flush periodically, so a job that
> throws exports nothing — a failed reactor leaves a trace that simply stops
> after the job was queued, with no error anywhere. The Compose overlay sets
> this for you; any other deployment must set it explicitly.

> The sampler variables are the `keepsuit/laravel-opentelemetry` names, **not**
> the OpenTelemetry spec's `OTEL_TRACES_SAMPLER` / `OTEL_TRACES_SAMPLER_ARG`.
> Setting the spec names has no effect.

### Locker client

| Variable                      | Meaning                                                        |
| ----------------------------- | -------------------------------------------------------------- |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Collector base URL. **Unset means the SDK is never loaded.**    |
| `OTEL_SERVICE_NAME`           | Defaults to `open-locker-client`.                              |
| `OTEL_SERVICE_VERSION`        | Optional; useful for telling image versions apart in a fleet.   |
| `OTEL_SDK_DISABLED`           | `true` switches tracing off even with an endpoint set.          |

A Pi points at the collector's host address, not `otel-collector` — that
hostname only resolves inside the Compose network. The overlay publishes port
4318 on the host for exactly this reason.

Every locker reports under the one service name `open-locker-client`,
distinguished by `service.instance.id`, which is the locker UUID. Fleet-wide
questions ("are all clients slow?") work by default; a single locker is a
filter, not a separate service.

## Walking one open flow

With the overlay running and a locker client (or the fleet simulator)
connected, open a compartment from the app or the admin panel, then find the
most recent `open-locker-backend` trace in your trace UI.

A healthy trace has roughly this shape:

```
POST /api/compartments/{compartment}/open       (open-locker-backend)
├── send events                                 producer — reactor queued
└── process events                              consumer — the queued reactor
    └── mqtt publish locker/{uuid}/command      producer
        └── mqtt process locker/{uuid}/command  consumer — open-locker-client
            ├── modbus flash_relay              the physical pulse
            └── mqtt publish locker/{uuid}/response
                └── mqtt process locker/{uuid}/response   (open-locker-backend)
```

The door-open outcome arrives separately, since a command response only
acknowledges that the pulse was sent:

```
mqtt publish locker/{uuid}/event                (open-locker-client)
└── mqtt process locker/{uuid}/event            (open-locker-backend)
```

### Reading it

- **The trace stops after `mqtt publish .../command`.** The command reached the
  broker and nothing picked it up. The client is offline, subscribed to a
  different UUID, or not running with tracing configured.
- **`mqtt process .../command` exists but no `modbus flash_relay`.** The command
  was rejected before it reached hardware — schema validation, the protocol
  guard, or transaction deduplication. The span's own logs say which.
- **`modbus flash_relay` is slow.** The span opens when the operation is queued,
  not when it reaches the wire, so a long span means the Modbus bus was busy.
  Operations on that bus are serialized deliberately.
- **A red `modbus read_discrete_inputs`.** A board stopped answering. Door reads
  degrade to `unknown` rather than failing the request, so this is visible on
  the trace but not in the API response.

### Attributes worth filtering on

The same keys are used by both services, so one query spans them:

| Attribute                        | Use                                              |
| -------------------------------- | ------------------------------------------------ |
| `open_locker.transaction_id`     | Follow one command end to end.                   |
| `open_locker.command_id`         | Correlate with the API's `command_id`.           |
| `open_locker.locker_uuid`        | Everything for one locker bank.                  |
| `open_locker.compartment_number` | Everything for one compartment.                  |
| `open_locker.modbus.slave_id`    | Everything that touched one board.               |
| `messaging.destination.name`     | One MQTT topic.                                  |

## What is deliberately not traced

Heartbeats and compartment snapshots produce **no spans**, on either side.
They arrive on a timer from every locker forever, and tracing them would bury
the flows worth looking at. The same goes for the health and broker-auth
endpoints (`up`, `api/mosq/*`).

Those facts are not lost — door states are still recorded as stored events.
Only their transport timing goes unmeasured.

Sampling is 100% by default. This is a small fleet, and a 10% sample would
usually discard the incident you are investigating. Volume is controlled by not
instrumenting the chatter, rather than by throwing away traces.

## Logs in the dashboard

Both services ship log records to the collector alongside their spans.

**Backend** — enabled by two variables the overlay sets for you:

```
OTEL_LOGS_EXPORTER=otlp
LOG_STACK=single,otlp
```

`otlp` is *added* to the stack, not substituted for `single`, so the log file
survives a collector outage. Anything logged inside a span carries its trace id.

**Locker client** — automatic once `OTEL_EXPORTER_OTLP_ENDPOINT` is set. A
winston transport sits next to the console one, so `docker logs` on the Pi keeps
working unchanged.

Logs written outside any span — startup messages, for instance — arrive with no
trace id. That is correct, not a fault: they do not belong to a flow.

### What is deliberately not shipped

Broker authorization decisions (`ACL Check`, `ACL Backend: …`) go to a dedicated
`broker` log channel that is not part of the telemetry stack. Mosquitto asks on
every publish and every subscribe, so at fleet scale these would dominate the
dashboard completely. They remain in the log file, which is where you want them
when debugging an auth problem.

## From a log line to a trace

Every log line emitted inside a span carries `trace_id`, in both services:

```json
{ "level": "info", "message": "MQTT event message received", "trace_id": "47d477a63d07a46f686354aee445da4a" }
```

Search that id in your trace UI to get the whole flow it came from. This
works whether or not an exporter is configured on the process that logged it,
which makes it useful even on a Pi you have not pointed at a collector.

## Secrets

Provisioning tokens, MQTT credentials, and password material are never span
attributes, and whole payloads are never attached to spans.

Registration topics embed the provisioning token in their path
(`locker/register/{token}`), so both the span name and the destination
attribute use the template rather than the token. HTTP headers are recorded
only when explicitly allow-listed, and the allow-list is empty, so
`Authorization` and `Cookie` cannot reach a span. Tests on both sides assert
this.
