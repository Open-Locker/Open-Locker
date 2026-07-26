# Locker simulator — how to run it

Emulates one or many locker banks without hardware. The backend cannot tell a
simulated bank from a real one.

Design rationale: [ADR-0027](adr/0027-contract-aligned-locker-fleet-simulator.md).

Needs the backend stack running and at least one locker bank in your **local**
database. Never point it at production — it publishes under a real bank's
identity and would knock the actual device off the broker. It refuses to start
when `APP_ENV`/`NODE_ENV` is `production`.

---

## Step 1 — Create a locker bank and copy its token

**Setup → Locker banks → New**, add its compartments, then click the token in the
**Provisioning Token** column to copy it. One bank per simulated device; name them
obviously (`sim-main`) so nobody later mistakes one for hardware.

Same token a real Pi would use — it just goes into the scenario file instead of
the Pi's `.env`.

## Step 2 — Write a scenario

```bash
cd locker-client
cp simulator-scenario.yml.example simulator-scenario.yml
```

Edit the copy — it is git-ignored, so your tokens stay out of the repo:

```yaml
broker_url: mqtt://localhost:1883

banks:
  - name: main                       # label for logs and console commands
    provisioning_token: <paste it here>
    heartbeat_interval_seconds: 15
    compartments:
      - compartment_number: 1
        slaveId: 1
        address: 0
        door_state: closed
      - compartment_number: 2
        slaveId: 1
        address: 1
        door_state: open
      - compartment_number: 3
        slaveId: 1
        address: 2
        door_state: closed
        jammed: true                 # relay fires, door never moves
```

`jammed` (default `false`) makes a compartment answer the relay normally while
its door stays shut — a jam, a blocked door, a worn latch. It is how you
reproduce a failed open; see [Testing a jammed door](#testing-a-jammed-door).

`slaveId`/`address` need not match real hardware, but must be unique within a
bank. The compartment list is a **seed** — an `apply_config` from the backend
takes over at runtime, as on real hardware.

`compartment_number` must match a compartment that exists on the bank: the YAML
does not create them. Numbers the backend does not know are skipped; compartments
you leave out stay `unknown`.

## Step 3 — Bootstrap credentials

`locker-client/.env` does not exist by default. Create it:

```bash
# locker-client/.env
MQTT_DEFAULT_USERNAME=provisioning_client
MQTT_DEFAULT_PASSWORD=a_public_password
MQTT_BROKER_URL=mqtt://localhost:1883
CONFIG_DIR=.
LOG_LEVEL=info
```

`CONFIG_DIR` defaults to `/config` (the Docker layout), so without it `pnpm sim`
will not find the scenario you created in Step 2.

`MQTT_DEFAULT_*` is the shared bootstrap account used only during provisioning,
mirroring the backend's `MQTT_PROVISIONING_USERNAME`/`PASSWORD` — not the device's
own credentials. `MQTT_BROKER_URL` is a fallback; the scenario's `broker_url`
wins.

Don't copy `.env.example` wholesale — its `MQTT_BROKER_URL` points at production.

## Step 4 — Run it

```bash
pnpm sim                                    # uses $CONFIG_DIR/simulator-scenario.yml
pnpm sim --scenario path/to/other.yml       # or point at any file
```

```
[main] → state/heartbeat  uptime=0s
[main] → state/compartments (retained)  #1:closed #2:open
```

`Ctrl+C` stops it — it is a foreground process, not a service.

## Step 5 — Check it arrived

**Locker banks** shows `last_heartbeat_at` ticking; **Compartments** shows your
door states.

---

## Using it

While it runs, type commands at the prompt:

```
list                     show every bank and its door states
open main 1              mark compartment 1 of bank "main" open
close main 1             mark it closed
unknown main 1           mark its state unknown (sensor failure)
jam main 1               relay still fires, but the door stays shut
unjam main 1             let it open normally again
quit                     shut down
```

`list` marks jammed compartments with `[jammed]`.

Every door change publishes a fresh retained snapshot, so the read model and the
mobile realtime path update as they would from real hardware.

Traffic is echoed to the console (`←` inbound, `→` outbound):

```
[main] ← command  open_compartment #1 tx=13a275c2
[main] → state/compartments (retained)  #1:open #2:open
[main] → response  open_compartment success tx=13a275c2
```

### Testing a jammed door

A successful unlock pulse does not mean the door opened. The client watches the
door sensor after firing and reports the outcome separately, on the event channel
(ADR-0031). Jam mode is how you exercise the failure path without hardware.

```
jam main 1
```

Then trigger an open on compartment 1 from the backend or admin panel. You will
see the pulse acknowledged immediately, the snapshot stay `closed`, and — after
the bank's `heartbeat_interval_seconds` — the failure event:

```
[main] ← command  open_compartment #1 tx=jam-test
[main] → state/compartments (retained)  #1:closed #2:open
[main] → response  open_compartment success tx=jam-test     ← pulse sent, nothing more
[main] → event                                              ← ~10s later
```

The event payload:

```json
{
  "event": "compartment_open_failed",
  "data": {
    "compartment_number": 1,
    "transaction_id": "jam-test",
    "outcome": "door_jammed",
    "error_code": "DOOR_JAMMED"
  }
}
```

`unjam main 1` and repeat: the same command now produces
`compartment_open_detected` with `outcome: opened` and a `detection_ms` of
roughly one poll interval.

Opening a door by hand with no command behind it (`open main 2` while nothing is
pending) publishes `compartment_uncommanded_open` instead — the break-in case.

### Flags

| Flag                 | Effect                                        |
| -------------------- | --------------------------------------------- |
| `--scenario <path>`  | Use a different scenario file                 |
| `--broker <url>`     | Override the broker URL                       |
| `--quiet`            | Stop echoing MQTT traffic                     |
| `--no-interactive`   | Do not read commands from stdin (scripts, CI) |
| `--allow-production` | Permit running with `APP_ENV=production`      |

---

## Important: a bank provisions only once

`provisioned_at` is set on first provisioning and every later attempt is refused,
so the credentials issued on that first run are the only ones you get. The
simulator caches them in `.simulator-credentials.json` beside your scenario file
(git-ignored, `0600`, override with `SIMULATOR_CREDENTIALS_FILE`).

**Do not delete that file** — without it the bank cannot be simulated again until
its provisioning is reset:

```bash
cd locker-backend
docker compose exec -u www-data app php artisan tinker --execute='
foreach (\App\Models\LockerBank::whereIn("name", ["sim-main"])->get() as $b) {
    \App\Models\MqttUser::where("locker_bank_id", $b->id)->delete();
    $b->forceFill(["provisioned_at" => null])->save();
    echo $b->name." reset\n";
}'
```

---

## Troubleshooting

**"Locker bank is already provisioned."**
The bank provisioned before, and the credential cache is missing. Reset it as
above, or restore the cache file.

**"Simulator scenario file not found"**
Default path is `$CONFIG_DIR/simulator-scenario.yml`. Pass `--scenario <path>` or
set `CONFIG_DIR`.

**"Missing MQTT_DEFAULT_USERNAME or MQTT_DEFAULT_PASSWORD"**
Step 3 — the bootstrap credentials are not in your environment.

**"Provisioning timed out"**
The broker is unreachable or the token is wrong. Check `broker_url` (from the
host it is `localhost:1883`; from inside the compose network it is `mqtt:1883`)
and that the token matches a bank in the database you are running against.

**Heartbeats arrive but `connection_status` stays `unknown`**
A known backend gap, not a simulator fault: the status only flips to `online`
when a bank recovers from `offline`, so a freshly provisioned bank stays
`unknown` while heartbeating normally. `last_heartbeat_at` still updates.

**Duplicate `provisioning_token`**
Two banks cannot share a token — same token means same locker UUID, and the
broker drops one on session takeover. The scenario loader rejects this at
startup.

---

## What it does not cover

The simulator replaces everything **above** the hardware port, so it cannot
exercise anything below it: Modbus framing, RTU inter-frame timing
([ADR-0029](adr/0029-enforce-modbus-rtu-inter-frame-delay.md)), relay wiring, or
reconnect behaviour against a real board. Those still need hardware.
