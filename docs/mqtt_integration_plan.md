# MQTT Integrationsplan

Dieses Dokument beschreibt den Integrations- und Migrationskontext für MQTT in
Open-Locker. Es ist **nicht** mehr der verbindliche MQTT-Protokollvertrag.

Der kanonische Vertrag liegt in:

- `docs/asyncapi/mqtt.yaml`
- `docs/asyncapi/schemas/`
- `docs/asyncapi/examples/`

Die Architekturentscheidungen dazu sind dokumentiert in:

- `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
- `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
- `docs/adr/0017-split-mqtt-state-topics-by-lifecycle.md`

Dieses Dokument bleibt als Überblick über Zielbild, Rollen, Betrieb und offene
Migrationspunkte erhalten.

## 1. Komponentenübersicht

- **MQTT Broker**: Mosquitto via Docker
- **Broker Auth/ACL**: `mosquitto-go-auth` mit HTTP-Backend in Laravel
- **Backend**: Laravel `locker-backend`
- **Backend MQTT Client**: `php-mqtt/laravel-client`
- **IoT Client**: Node.js `locker-client`
- **Mobile App**: konsumiert Backend-API, nicht MQTT direkt

## 2. Broker, Auth und ACL

Mosquitto läuft als Broker und ruft Laravel für Authentifizierung und ACLs auf.
Die Laravel-Endpunkte liegen im `MosquittoAuthController`:

- `/api/mosq/auth`
- `/api/mosq/acl`

Die Rollen sind:

- **Backend User**
  - darf die relevanten MQTT-Topics lesen und schreiben
  - wird für Publisher und Listener verwendet
- **Locker Device User**
  - entspricht dem jeweiligen `locker_uuid` / MQTT-Username
  - darf seine eigenen Command-Topics lesen
  - darf seine eigenen Response-, Event- und State-Topics schreiben
- **Provisioning User**
  - darf Registrierungsnachrichten publishen
  - darf nur sein eigenes Provisioning-Reply-Topic abonnieren

Aktuelle Device-Topic-ACLs orientieren sich an:

- subscribe: `locker/%u/command`
- publish: `locker/%u/response`
- publish: `locker/%u/event`
- publish: `locker/%u/state/#`

`locker/%u/state/#` umfasst:

- `locker/%u/state/heartbeat`
- `locker/%u/state/compartments`
- `locker/%u/state/connection`

## 3. Zuverlässigkeit und Sessions

Alle fachlich relevanten MQTT-Nachrichten werden mit QoS 1 gesendet. QoS 1
bedeutet *at least once*: Nachrichten können erneut zugestellt werden.
Empfänger müssen daher idempotent arbeiten.

Der locker-client nutzt eine persistente MQTT-Session:

- MQTT v3.1.1: `clean_session=false`
- mqtt.js: `clean=false`

Der Client reconnectet standardmäßig unbegrenzt. Details dazu sind in
`docs/adr/0014-locker-client-mqtt-session-and-reconnect.md` dokumentiert.

## 4. Kanonische Topic-Struktur

Die folgenden Topics sind die fachliche MQTT-Oberfläche. Details zu Payloads,
Pflichtfeldern und Beispielen stehen in der AsyncAPI-Spezifikation.

### Runtime Topics


| Richtung          | Topic                                     | Zweck                                     |
| ----------------- | ----------------------------------------- | ----------------------------------------- |
| Backend -> Client | `locker/{locker_uuid}/command`            | transaktionsgebundene Commands            |
| Client -> Backend | `locker/{locker_uuid}/response`           | transaktionsgebundene Command-Responses   |
| Client -> Backend | `locker/{locker_uuid}/event`              | spontane Device-/Domain-Events            |
| Client -> Backend | `locker/{locker_uuid}/state/heartbeat`    | nicht-retained Liveness-Heartbeat         |
| Client -> Backend | `locker/{locker_uuid}/state/compartments` | retained Full Snapshot aller Compartments |
| Client -> Backend | `locker/{locker_uuid}/state/connection`   | nicht-retained Connection-/LWT-Signal     |


### Provisioning Topics


| Richtung                       | Topic                                   | Zweck                |
| ------------------------------ | --------------------------------------- | -------------------- |
| Provisioning Client -> Backend | `locker/register/{provisioning_token}`  | Provisioning Request |
| Backend -> Provisioning Client | `locker/provisioning/reply/{client_id}` | Provisioning Reply   |


### Entfernte Legacy Topics

Diese Topics sind nicht mehr Teil des Vertrags:

- `locker/{locker_uuid}/status`
- multiplexed `locker/{locker_uuid}/state` mit `state` oder `event` im Payload

## 5. Globale Payload-Regeln

Für alle MQTT-Nachrichten gilt:

- `message_id` ist Pflicht
- `timestamp` ist Pflicht und liegt top-level
- `transaction_id` ist nur für `command` und `response` Pflicht

Für retained `state/compartments` gilt eine eng begrenzte Dedup-Ausnahme:

- `message_id` bleibt Pflicht
- Retained-Replays dürfen nicht allein wegen gleicher `message_id` blockiert
werden
- Die Verarbeitung muss idempotent sein

Für Commands, Responses, Events, Provisioning, Heartbeats und Connection-Signale
bleibt `message_id`-Dedup blockierend.

## 6. Commands

Commands laufen über:

`locker/{locker_uuid}/command`

Aktuelle Command-Actions:

- `open_compartment`
- `apply_config`

### `open_compartment`

Der Client öffnet ein Fach anhand der fachlichen Fachnummer.

```json
{
  "message_id": "msg-cmd-open-001",
  "transaction_id": "txn-open-001",
  "action": "open_compartment",
  "timestamp": "2026-04-14T19:30:00Z",
  "data": {
    "compartment_number": 3
  }
}
```

`compartment_id` ist nicht Teil des MQTT-Contracts für diesen Command.

### `apply_config`

Das Backend übermittelt die serververwaltete Laufzeit-Konfiguration für die
Compartment-Zuordnung.

```json
{
  "message_id": "msg-cmd-config-001",
  "transaction_id": "txn-config-001",
  "action": "apply_config",
  "timestamp": "2026-04-14T19:31:00Z",
  "data": {
    "config_hash": "12d9df0453da04ed41b9dd21c603715cb4370cfb21958aadc5f73fc195848003",
    "heartbeat_interval_seconds": 15,
    "adapter_type": "rs485_lock_board",
    "channel_count": 12,
    "feedback_type": "door_closing",
    "compartments": [
      {
        "compartment_number": 1,
        "slaveId": 1,
        "address": 0
      }
    ]
  }
}
```

`compartment_number` bezeichnet dieselbe fachliche Nummer wie bei
`open_compartment`.

`adapter_type`, `channel_count` und `feedback_type` sind bankweite
Hardwareprofil-Felder. `address` ist nullbasiert und muss kleiner als
`channel_count` sein.

`config_hash` ist der hexadezimale SHA-256-Hash der kompakten
UTF-8-JSON-Serialisierung eines Objekts mit genau diesen Schlüsseln in dieser
Reihenfolge:

1. `adapter_type`
2. `channel_count`
3. `feedback_type`
4. `compartments`

`compartments` wird vor der Serialisierung aufsteigend nach
`compartment_number` sortiert. Die Serialisierung enthält keine zusätzlichen
Leerzeichen. `heartbeat_interval_seconds` liegt ausdrücklich außerhalb des
Hashes.

## 7. Responses

Responses laufen über:

`locker/{locker_uuid}/response`

Responses sind transaktionsgebunden und referenzieren die ursprüngliche
`transaction_id`.

Bei Erfolg ist `message` optional:

```json
{
  "message_id": "msg-resp-open-001",
  "transaction_id": "txn-open-001",
  "action": "open_compartment",
  "result": "success",
  "timestamp": "2026-04-14T19:30:01Z"
}
```

Bei Fehlern sind `error_code` und `message` Pflicht:

```json
{
  "message_id": "msg-resp-open-err-001",
  "transaction_id": "txn-open-001",
  "action": "open_compartment",
  "result": "error",
  "timestamp": "2026-04-14T19:30:01Z",
  "error_code": "DOOR_JAMMED",
  "message": "Could not open compartment, mechanism is jammed."
}
```

Ein erfolgreicher `apply_config`-Response muss `applied_config_hash` enthalten.

## 8. State Topics

State ist nach Lifecycle und Retain-Semantik aufgeteilt.

### Heartbeat

Topic:

`locker/{locker_uuid}/state/heartbeat`

Retain:

`false`

Payload:

```json
{
  "message_id": "msg-state-heartbeat-001",
  "timestamp": "2026-04-14T19:33:00Z",
  "uptime_seconds": 86400
}
```

Heartbeat ist ein Liveness-Signal. Im Backend darf `last_heartbeat_at` als
Telemetry/Liveness-Feld aktualisiert werden. Fachliche Statusübergänge wie
offline -> online laufen über Stored Events und Projectors.

### Compartment Snapshot

Topic:

`locker/{locker_uuid}/state/compartments`

Retain:

`true`

Payload:

```json
{
  "message_id": "msg-state-snapshot-001",
  "timestamp": "2026-04-14T19:33:05Z",
  "compartments": [
    {
      "compartment_number": 1,
      "door_state": "closed"
    },
    {
      "compartment_number": 2,
      "door_state": "open"
    }
  ]
}
```

Der Snapshot ist ein vollständiges Bild aller konfigurierten Compartments, kein
Delta. Der Client sendet:

- nach dem ersten erfolgreichen Poll
- danach nur, wenn sich mindestens ein effektiver `door_state` ändert

Erlaubte `door_state`-Werte:

- `open`
- `closed`
- `unknown`

Kann ein konfiguriertes Compartment nicht zuverlässig gelesen werden, wird es
als `unknown` gemeldet.

Backend-seitig werden effektive Türzustandsänderungen event-sourced:

- `CompartmentDoorStateChanged`
- `CompartmentStateChangesApplied`

Projectors aktualisieren die Read Models:

- `compartments.door_state`
- `compartments.door_state_changed_at`
- `locker_banks.last_compartment_state_change_at`

Unveränderte retained Snapshot-Replays erzeugen keine Stored Events.

### Connection / LWT

Topic:

`locker/{locker_uuid}/state/connection`

Retain:

`false`

Payload:

```json
{
  "message_id": "msg-state-lwt-001",
  "timestamp": "2026-04-14T19:34:00Z",
  "status": "offline",
  "reason": "mqtt_last_will"
}
```

Das Backend validiert und loggt dieses Signal aktuell nur. Die Produktsemantik
von MQTT Last Will ist noch nicht final entschieden. Heartbeat Timeout bleibt
bis dahin die maßgebliche Offline-Erkennung.

## 9. Events

Events laufen über:

`locker/{locker_uuid}/event`

Events sind nicht transaktionsgebunden. Sie enthalten `message_id`, `timestamp`
und einen Event-Namen.

Der Event-Contract ist noch zu prüfen. Insbesondere ist zu klären, welche
Device-Events nach Einführung von retained Compartment Snapshots noch benötigt
werden.

## 10. Provisioning

Provisioning bleibt ein separater Workflow und ist kein Runtime-Command.

Request:

`locker/register/{provisioning_token}`

Reply:

`locker/provisioning/reply/{client_id}`

Der Provisioning-Contract ist als nächstes gezielt zu prüfen und gegen
Backend/Client-Implementierung sowie AsyncAPI-Schemas abzugleichen.

## 11. Event Sourcing im Backend

Open-Locker nutzt für relevante Domain-Änderungen Event Sourcing.

Leitlinie:

- MQTT Handler validieren Payloads und erzeugen Stored Events für fachliche
Änderungen.
- Projectors aktualisieren Read Models.
- Reactors lösen Side Effects aus, z.B. Realtime Broadcasts.

Für Compartment Door State gilt:

- MQTT Snapshot -> effektive Änderung erkannt
- Stored Events werden aufgezeichnet
- Projectors aktualisieren `Compartment` und `LockerBank`
- Reactor sendet `CompartmentDoorStateUpdated` für Mobile/Admin UI

Broadcasts sind Side Effects und nicht Source of Truth.

## 12. Offene Migrationspunkte

Die folgenden Punkte sind noch offen oder in separaten Tickets abgebildet:

- Provisioning-Request/Reply gegen AsyncAPI prüfen und umsetzen
- Event-Topic fachlich prüfen
- Contract Tests gegen JSON Schemas einführen
- AsyncAPI-Validierung in CI einhängen
- Dieses Dokument weiter reduzieren, sobald alle historischen Migrationshinweise
in ADRs oder Issues aufgegangen sind

