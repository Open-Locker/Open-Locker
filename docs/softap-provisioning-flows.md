# SoftAP provisioning flows

Diagrams for [ADR-0074](adr/0074-esp32-softap-provisioning.md). The durations are
design parameters.

## Commissioning over the setup network

Who does what in `commission.py --softap`: the operator, the tool on the laptop
or phone, and the unit. The setup password is proven by both sides and never
sent; `ol-config` and the Wi-Fi settings travel inside the Security 2 session.

![SoftAP commissioning activity: operator, tool and unit](softap-commissioning-activity.svg)

## Boot mode

```mermaid
flowchart TD
    A[Power-up] --> B{Identity empty and<br/>settings present?}
    B -- yes --> C[Finish the wipe:<br/>clear settings and mapping]
    B -- no --> D
    C --> D{Settings stored<br/>or unit enrolled?}
    D -- yes --> E[Station mode.<br/>No setup network]
    D -- no --> F[Open setup network<br/>for 10 minutes]
    F --> G{Settings saved?}
    G -- yes --> H[Apply, close portal,<br/>restart as station]
    G -- no, 10 min or 5 failed sessions --> I[Close portal.<br/>Reopens on next boot]
```

## Commissioning from a laptop

```mermaid
sequenceDiagram
    participant Admin as Admin panel
    participant Lap as Installer laptop (esp_prov)
    participant Unit as Unit (SoftAP)
    participant Broker
    Admin->>Admin: Issue token (valid 24 h)
    Lap->>Unit: Join AP (label passphrase)
    Lap->>Unit: Security 2 session (label setup password)
    Lap->>Unit: Wi-Fi settings + ol-config (encrypted)
    Unit->>Unit: Validate, save all or nothing
    Unit-->>Lap: saved
    Unit->>Unit: Close portal, join Wi-Fi
    Unit->>Broker: Register with token
    Broker-->>Unit: Issued MQTT credentials
    Admin->>Admin: Bank shows connected
```

## Reset gesture

```mermaid
stateDiagram-v2
    [*] --> Idle
    Idle --> Armed: released for 1 s
    Armed --> Held: button down
    Held --> Armed: released under 10 s (nothing)
    Held --> Reset: released at 10 s to under 30 s
    Held --> Void: held 30 s or more
    Reset --> Idle: wipe identity, mapping, settings (journal kept)
    Void --> Idle
```

A press at power-up, a stuck pin, a sample gap or a floating pin never arms
the gesture. Bounce under 50 ms is ignored. A rejected reset post is retried on
the next samples for about 2 s, then logged.

## Changing Wi-Fi on a deployed unit

Reset (button, 10 s), then issue a new token in the admin panel and commission
again. Production builds have no `configure`, and the portal never opens on an
enrolled unit.
