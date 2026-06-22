# MQTT-basierte Architektur

Dies ist eine Visualisierung der neuen, auf MQTT basierenden Systemarchitektur.

```mermaid
graph LR
    subgraph "Benutzer & Admin"
        User["Endbenutzer"]
        Admin["Administrator"]
    end

    subgraph "Mobile App"
        MobileApp["React Native App"]
    end

    subgraph "Cloud-Infrastruktur (open-locker.de)"
        LaravelBackend["Laravel Backend<br/>API & Admin Panel"]
        Mosquitto["Mosquitto<br/>MQTT Broker"]
        Database["Datenbank<br/>(PostgreSQL/MySQL)"]
    end

    subgraph "IoT-Ebene (Schließfach-Standort)"
        LockerClient["Locker Client<br/>(IoT-Gerät)"]
        PhysicalLockers["Physische Schließfächer"]
    end

    %% User Flows
    User --> MobileApp
    Admin -- "Admin UI" --> LaravelBackend

    %% API Flow (Command)
    MobileApp -- "REST API Request (HTTPS)" --> LaravelBackend
    LaravelBackend -- "Validierung & Logik" --> Database
    LaravelBackend -- "Befehl publishen" --> Mosquitto
    Mosquitto -- "MQTT Command" --> LockerClient
    LockerClient -- "Modbus-Signal" --> PhysicalLockers

    %% Status Flow (Feedback)
    PhysicalLockers -- "Status-Änderung" --> LockerClient
    LockerClient -- "MQTT Status" --> Mosquitto
    Mosquitto -- "Status empfangen" --> LaravelBackend
    LaravelBackend -- "Status speichern" --> Database
    LaravelBackend -- "Echtzeit-Update (Push/SSE)" --> MobileApp
```

## Health des MQTT-Listeners

Der `mqtt-listener`-Container (`php artisan mqtt:listen`) meldet seine Liveness
über einen Heartbeat (siehe ADR-0025):

- Der Listener schreibt bei jeder Loop-Iteration einen Heartbeat-Zeitstempel in
  den Cache (gedrosselt auf `MQTT_LISTENER_HEARTBEAT_INTERVAL`, Standard 10s).
- `php artisan mqtt:health` ist der `healthcheck` des Containers: Exit `0`
  (healthy), wenn der Heartbeat jünger als `MQTT_LISTENER_HEARTBEAT_MAX_AGE`
  (Standard 35s) ist, sonst Exit `1` (unhealthy).

Status-Interpretation:

- **healthy** — die Listener-Loop läuft (bleibt auch healthy, während der Broker
  kurz offline ist / reconnectet, da der Puls die Loop misst, nicht den
  Nachrichtenfluss).
- **unhealthy** — kein frischer Puls: Prozess hängt/wedged oder Cache nicht
  erreichbar. Der `autoheal`-Sidecar startet jeden `unhealthy` Container mit dem
  Label `autoheal: "true"` neu (Plain Compose startet bei Health-Status nicht
  von selbst neu).

Hinweis: `autoheal` startet über die Docker-`restart`-API neu, daher erhöht sich
`RestartCount` **nicht** — Restarts in den `autoheal`-Logs bzw. an `StartedAt`
prüfen.
