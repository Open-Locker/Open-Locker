# ADR-0025: Liveness healthcheck for the mqtt-listener container

## Status

Accepted

## Date

2026-06-22

## Context

The `mqtt-listener` service runs `php artisan mqtt:listen` as its own
long-running container in both `locker-backend/docker-compose.yml` (dev) and
`docker-compose.prod.yml` (prod). It subscribes to the inbound MQTT topics and
dispatches messages to the handler classes.

Every other long-running worker in the stack already has a Docker `healthcheck:`
provided by the `serversideup/php` base image:

- queue / event workers → `healthcheck-queue`
- scheduler → `healthcheck-schedule`
- reverb → `healthcheck-reverb`

`mqtt-listener` is the **only** worker without one. `mqtt:listen` is a custom
command, so no built-in `serversideup` healthcheck binary covers it.

Note that those built-in binaries are **process-existence checks only** — each
is a `pgrep` for the worker command, e.g.:

```sh
# healthcheck-queue
pgrep -f "queue:work" > /dev/null && exit 0 || exit 1
```

A `pgrep` check answers "does the PID exist?" — it catches a crash, but a
process that is alive yet **wedged** (frozen loop, deadlock, half-open socket)
still has a live PID and reads as healthy. That blind spot is exactly the
failure mode this ADR targets (see failure mode 2 below).

The command's core is a single blocking loop:

```php
$mqtt = MQTT::connection('listener');
foreach ($this->handlers() as $handler) {
    $this->subscribe($mqtt, $handler);
}
$mqtt->loop(true); // blocks forever
```

Two distinct failure modes exist:

1. **Process exits / crashes** — e.g. the broker connection drops and
   `loop()` throws; the command returns `Command::FAILURE` and the container
   (`restart: unless-stopped`) restarts. *This case is already self-healing.*
2. **Process alive but wedged** — the PID is still up, but the loop is stuck
   (deadlocked callback, silent stall, half-open socket). Docker's default
   process-liveness only sees "PID exists" and considers the container healthy,
   so it never restarts. **This is the gap this ADR addresses.**

Key constraint from issue #52: the check must not produce **false negatives
during broker downtime**. A listener can be alive and merely reconnecting/idle
with no messages flowing — that must still read as healthy.

## Decision

Add a **heartbeat-based liveness healthcheck** for `mqtt-listener`.

The listener periodically emits a "pulse" (a timestamp). A lightweight check
command reads that pulse and reports the container healthy only if the pulse is
recent. Docker runs the check on an interval; an **autoheal sidecar** watches
the resulting health status and restarts the container when it goes unhealthy.

> **Why a sidecar is required.** In plain Docker Compose a failing
> `healthcheck:` only flips the container's status to `unhealthy` — it does
> **not** restart anything. `restart: unless-stopped` reacts to the process
> *exiting* (a crash), not to health status. Our stack is plain Compose (no
> Swarm `deploy:` / orchestrator), so the healthcheck alone would detect a
> wedged listener but leave it running unhealthy. A small autoheal container
> (`willfarrell/autoheal`) closes that loop by restarting any container marked
> unhealthy. See step 4.

### 1. Emit the pulse from the loop (not from message receipt)

Use php-mqtt's `registerLoopEventHandler()`, which fires on **every loop
iteration** regardless of whether a message arrived. Throttle writes to ~every
10s to avoid hammering Redis:

```php
$lastBeat = 0.0;
$mqtt->registerLoopEventHandler(function () use (&$lastBeat): void {
    $now = microtime(true);
    if ($now - $lastBeat >= 10) {           // throttle
        Cache::store('redis')->put('mqtt-listener:heartbeat', time(), 60);
        $lastBeat = $now;
    }
});
$mqtt->loop(true);
```

The pulse therefore means **"the listener's loop is turning"**, *not* "messages
are arriving" — satisfying the broker-downtime constraint.

### 2. A health check command

A new artisan command reads the pulse and exits `0` (healthy) / `1` (unhealthy):

```php
// php artisan mqtt:health
$beat = Cache::store('redis')->get('mqtt-listener:heartbeat');
$stale = $beat === null || (time() - (int) $beat) > config('mqtt-listener.heartbeat_max_age', 35);
return $stale ? self::FAILURE : self::SUCCESS;
```

Heartbeat written every ~10s; considered stale after ~35s (≈ 3 missed pulses).

### 3. Wire the healthcheck into both compose files

Mirror the existing worker healthcheck block on the `mqtt-listener` service:

```yaml
healthcheck:
  test: ["CMD", "php", "artisan", "mqtt:health"]
  start_period: 15s   # allow first connect + first pulse
  interval: 30s
  timeout: 10s
  retries: 3
```

Storage backend is **Redis**, which is already present in both compose files and
already used for `QUEUE_CONNECTION`/cache (`REDIS_HOST: redis`). No new
dependency, no shared volume.

### 4. Act on the health status with an autoheal sidecar

The healthcheck only produces a *status*. To turn an `unhealthy` listener into a
*restart* under plain Compose, add a single `autoheal` service that watches the
Docker socket and restarts any container labelled for autoheal:

```yaml
autoheal:
  image: willfarrell/autoheal:latest
  restart: unless-stopped
  environment:
    AUTOHEAL_CONTAINER_LABEL: autoheal
  volumes:
    - /var/run/docker.sock:/var/run/docker.sock
```

and opt the listener in with a label:

```yaml
mqtt-listener:
  labels:
    autoheal: "true"
```

Added to both dev and prod compose. Now: wedged loop → stale pulse →
`mqtt:health` fails → container marked `unhealthy` → autoheal restarts it →
fresh pulse → healthy. This completes the detect-and-recover loop.

## Rationale

- **A pulse proves work, not just existence.** The listener can only write a
  fresh timestamp if its loop is actually turning, so the check detects the
  "alive but wedged" case that a `pgrep` process-check (used by the other
  workers) is blind to. This makes the listener's healthcheck deliberately
  stronger than `healthcheck-queue`/`-schedule`/`-reverb`, not just on par.
- **Heartbeat in the loop handler** distinguishes "loop turning" from "messages
  flowing", which is precisely the false-negative trap the issue warns about. A
  message-flow probe would mark an idle-but-healthy listener as dead.
- **Redis over a file** avoids volume/permission coupling between the listener
  and any checker, and reuses infrastructure that is already a hard dependency.
- **Reusing the existing healthcheck pattern** keeps the compose files
  consistent with the queue/scheduler/reverb services and predictable for
  operators.
- **Staleness threshold = ~3× pulse interval** tolerates one or two missed beats
  (GC pause, brief load spike) before declaring death, reducing flapping.

## Alternatives Considered

### Alternative A: HTTP/route-based liveness

- Pros: Standard `CMD curl` healthcheck; visible from outside the container.
- Cons: The listener is a CLI process with no HTTP server; would require
  bolting on a web server purely for the probe. Heavy and out of character.
- Why not chosen: Disproportionate complexity for a CLI worker.

### Alternative B: Probe on message receipt / broker round-trip

- Pros: Confirms end-to-end MQTT connectivity, not just process liveness.
- Cons: Reports unhealthy during legitimate quiet periods or broker downtime —
  the exact false-negative the issue forbids; risks restart loops while the
  broker itself is down.
- Why not chosen: Violates the broker-downtime constraint.

### Alternative C: File-based heartbeat (touch a file on disk)

- Pros: No Redis dependency for the signal.
- Cons: Couples checker and writer through a shared writable path; volume and
  permission management; less natural in a multi-container setup.
- Why not chosen: Redis is already available and cleaner.

### Alternative D: Do nothing (rely on process-exit + restart)

- Pros: Zero work; crash-restart already works.
- Cons: Leaves the "alive but wedged" case completely undetected — the primary
  motivation for the ticket.
- Why not chosen: Does not solve the stated problem.

## Consequences

### Positive

- A wedged-but-running listener is detected and restarted automatically.
- `mqtt-listener` reaches parity with the other workers' health reporting.
- `docker ps` / orchestrators expose a meaningful health status for the service.

### Negative

- Small amount of new code (loop handler + `mqtt:health` command) and config.
- Each healthcheck run boots an artisan process (~every 30s) — minor CPU cost,
  consistent with the other artisan-based checks already in use.
- Adds an `autoheal` sidecar that mounts the Docker socket (read of container
  health + restart). It is the standard plain-Compose pattern, but it is an
  extra container with elevated access to consider.

### Risks

- **Threshold tuning**: too tight → flapping/restarts under load; too loose →
  slow detection. Mitigation: make pulse interval and max-age configurable;
  default to 10s / 35s and adjust from observed behaviour.
- **Redis unavailability**: if Redis is down, the pulse can't be written/read
  and the listener may be marked unhealthy. Acceptable: Redis is already a hard
  dependency of the stack, and a restart is a reasonable response.
- **Ops gotcha**: autoheal restarts via the Docker `restart` API, which does
  **not** increment the container's `RestartCount`. To confirm a restart
  happened, check the `autoheal` container logs or the listener's `StartedAt`,
  not `RestartCount`. Alerting on repeated restarts is out of scope here.

## Rollout / Migration

1. Add the loop-event heartbeat to `MqttListen`.
2. Add the `mqtt:health` artisan command + a feature test for the staleness
   logic (fresh → success, stale/missing → failure, repeated fail/heal cycles).
3. Add the `healthcheck:` block to `mqtt-listener` in dev + prod compose.
4. Add the `autoheal` sidecar + `autoheal: "true"` label on `mqtt-listener` in
   dev + prod compose, so unhealthy status actually triggers a restart.
5. Add `heartbeat.interval` / `heartbeat.max_age` to listener config.
6. Verify locally: confirm the container reports `healthy`; force a stale pulse
   and confirm `mqtt:health` fails, the container goes `unhealthy`, and autoheal
   restarts it back to `healthy`.

No data migration. Fallback: remove the `healthcheck:` block (and/or the
`autoheal` label) to revert to the current process-only liveness behaviour.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #52
- Related code: `app/Console/Commands/MqttListen.php`,
  `docker-compose.yml`, `docker-compose.prod.yml`
- Related ADRs: ADR-0008 (typed outbound MQTT publishers)
