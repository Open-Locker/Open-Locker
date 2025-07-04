# MQTT Integrationsplan

Dieses Dokument beschreibt den Plan für die Integration von MQTT in das
Open-Locker Backend, um eine verteilte Architektur zu ermöglichen.

## 1. Komponentenübersicht

- **MQTT Broker**: Mosquitto (via Docker)
- **PHP MQTT Client**: `php-mqtt/laravel-client`
- **Backend**: Laravel `locker-backend`
- **IoT Client**: Node.js Service (Verantwortung bei anderem Teammitglied)

## 2. MQTT Broker: Mosquitto Setup

Wir werden Mosquitto als Docker-Container in unsere `docker-compose.yml`
integrieren.

### Konfiguration (`mosquitto.conf`)

Die Konfiguration wird in einer separaten Datei gemountet und beinhaltet:

```conf
# Standard-Listener für MQTT ohne Verschlüsselung
listener 1883

# Deaktiviert anonymen Zugriff, alle Clients müssen sich authentifizieren
allow_anonymous false

# Pfade zur Passwort- und ACL-Datei
password_file /mosquitto/config/password.conf
acl_file /mosquitto/config/acl.conf
```

### Sicherheit: Authentifizierung & ACLs

Die Sicherheit ist entscheidend, um die Kommunikation zwischen Backend und den
vielen Locker-Clients zu schützen.

**Authentifizierung (`password.conf`)**: Jeder Client (Backend, jeder einzelne
Locker) erhält eigene Zugangsdaten. Die Passwörter werden gehasht mit
`mosquitto_passwd` gespeichert.

**Autorisierung (`acl.conf`)**: Wir nutzen das mächtige ACL-System von
Mosquitto, um die Berechtigungen feingranular zu steuern.

```conf
# Das Laravel Backend hat uneingeschränkten Zugriff auf alle Topics.
# Es muss Befehle an alle Locker senden und Status von allen empfangen können.
user laravel_backend
topic readwrite locker/#

# Ein spezifischer Locker-Client kann nur auf seine eigenen, zugewiesenen Topics zugreifen.
# Wir verwenden das '%u' Pattern, das Mosquitto automatisch durch den Benutzernamen des Clients ersetzt.
# Dies ermöglicht eine skalierbare Konfiguration, ohne für jeden neuen Locker die Datei anpassen zu müssen.
# Der Benutzername eines Locker-Clients entspricht seiner eindeutigen ID (z.B. UUID).
pattern read locker/%u/command
pattern write locker/%u/status
pattern write locker/%u/state

# --- NEU: Sicherere Regeln für den Provisioning-Client ---
# Dieser User darf sich nur für die Registrierung melden und auf die Antwort lauschen.
user provisioning_client
# Darf eine Anfrage an irgendein register-Topic senden
topic write locker/register/+
# Darf NUR auf dem Antwort-Topic lauschen, das seine EIGENE Client-ID enthält.
# Client "client-1" kann NICHT auf "locker/provisioning/reply/client-2" lauschen.
topic read locker/provisioning/reply/%c
```

## 3. Laravel Backend Integration

Wir verwenden das Paket
[`php-mqtt/laravel-client`](https://github.com/php-mqtt/laravel-client) für eine
nahtlose Integration.

### Installation & Konfiguration

1. **Paket installieren**:
   ```bash
   composer require php-mqtt/laravel-client
   ```

2. **Konfiguration publizieren**:
   ```bash
   php artisan vendor:publish --provider="PhpMqtt\Client\MqttClientServiceProvider" --tag="config"
   ```

3. **Umgebungsvariablen anpassen (`.env`)**: In `config/mqtt-client.php` werden
   wir die Konfiguration so anpassen, dass sie auf `.env`-Variablen zugreift.

   ```ini
   MQTT_HOST=mosquitto
   MQTT_PORT=1883
   MQTT_USER=laravel_backend
   MQTT_PASSWORD=ein_sicheres_passwort
   ```

### Implementierungsstrategie

#### Nachrichten Senden (Publishing)

Das Senden von Befehlen an die Locker (z.B. "Tür öffnen") erfolgt asynchron über
Laravel Queues, um die API-Antwortzeiten nicht zu beeinträchtigen.

1. **Event/Trigger**: Ein API-Aufruf von der Mobile App (z.B.
   `POST /api/lockers/{id}/open`).
2. **Job Dispatching**: Der Controller validiert die Anfrage und dispatcht einen
   Job in die Queue. `OpenLockerDoor::dispatch($locker);`
3. **Job Handler**: Der Job Handler nutzt die `MQTT`-Facade, um die Nachricht zu
   publizieren.

   ```php
   // App/Jobs/OpenLockerDoor.php
   use PhpMqtt\Client\Facades\MQTT;

   public function handle()
   {
       $topic = "locker/{$this->locker->uuid}/command";
       $payload = json_encode(['action' => 'open_door']);
       MQTT::publish($topic, $payload, 1); // QoS Level 1
   }
   ```

#### Nachrichten Empfangen (Subscribing)

Das Empfangen von Status-Nachrichten der Locker erfordert einen permanent
laufenden Prozess.

1. **Artisan Command**: Wir erstellen ein neues Command, z.B.
   `php artisan mqtt:listen`.
2. **Subscriber-Logik**: Innerhalb des Commands wird eine Verbindung zum MQTT
   Broker aufgebaut. Es abonniert die Wildcard-Topics für Status-Updates
   (`locker/+/status`).
   ```php
   // App/Console/Commands/MqttListen.php
   $mqtt = MQTT::connection();
   $mqtt->subscribe('locker/+/status', function (string $topic, string $message) {
       // Logik zur Verarbeitung der Nachricht:
       // 1. Locker-UUID aus Topic extrahieren.
       // 2. Status in der Datenbank aktualisieren.
       // 3. Event für Echtzeit-Updates an die App senden (z.B. via Laravel Reverb).
       Log::info("Received message on topic [$topic]: $message");
   }, 1);
   $mqtt->loop(true);
   ```
3. **Prozess-Management**: In der Entwicklung und Produktion wird dieses Command
   mit **Supervisor** gestartet und überwacht, um sicherzustellen, dass es immer
   läuft. Laravel Sail bringt eine `supervisor.conf` mit, die wir dafür anpassen
   können.

## 4. Kommunikationsprotokoll

Wir verwenden JSON als Nachrichtenformat. Das Protokoll definiert die Struktur
der Payloads für die verschiedenen Topics.

### Topic-Struktur

- **Registrierung (Client → Backend)**: `locker/register/{provisioning_token}`
- **Registrierung (Backend → Client)**:
  `locker/provisioning/reply/{unique_client_id}`
- **Befehle (Backend → Client)**: `locker/{locker_uuid}/command`
- **Status (Client → Backend)**: `locker/{locker_uuid}/status`
- **Zustandsdaten (Client → Backend)**: `locker/{locker_uuid}/state` (für
  regelmäßige, nicht-kritische Daten wie Heartbeats)

### Nachrichten-Payloads (JSON-Beispiele)

#### 4.1 Befehle (Backend sendet)

Ein Befehl enthält eine `action` und eine `transaction_id` zur Nachverfolgung.

- **Tür öffnen:**
  - Topic: `locker/uuid-123/command`
  - Payload:
    ```json
    {
        "action": "open_door",
        "transaction_id": "xyz-789",
        "data": { "door_number": 3 }
    }
    ```

#### 4.2 Status-Antworten (Client sendet)

Eine Status-Nachricht referenziert die `transaction_id` der ursprünglichen
Aktion oder meldet ein spontanes Ereignis.

- **Antwort auf `open_door` (Erfolg):**
  - Topic: `locker/uuid-123/status`
  - Payload:
    ```json
    {
        "event": "action_completed",
        "action": "open_door",
        "status": "success",
        "transaction_id": "xyz-789",
        "message": "Door 3 opened successfully."
    }
    ```

- **Antwort auf `open_door` (Fehler):**
  - Topic: `locker/uuid-123/status`
  - Payload:
    ```json
    {
        "event": "action_failed",
        "action": "open_door",
        "status": "error",
        "transaction_id": "xyz-789",
        "error_code": "DOOR_JAMMED",
        "message": "Could not open door 3, mechanism is jammed."
    }
    ```

- **Spontanes Event (z.B. Tür wurde manuell geschlossen):**
  - Topic: `locker/uuid-123/status`
  - Payload:
    ```json
    {
        "event": "door_state_changed",
        "status": "closed",
        "data": {
            "door_number": 3,
            "timestamp": "2023-10-27T10:00:00Z"
        }
    }
    ```

#### 4.3 Zustand (Client sendet)

Für regelmäßige "Heartbeats" oder Telemetriedaten.

- **Regelmäßiger Heartbeat:**
  - Topic: `locker/uuid-123/state`
  - Payload:
    ```json
    {
        "event": "heartbeat",
        "data": {
            "timestamp": "2023-10-27T10:01:00Z",
            "uptime_seconds": 86400
        }
    }
    ```

#### 4.4 Registrierungsprozess

Ein neuer Locker muss sicher provisioniert werden, bevor er am normalen Betrieb
teilnehmen kann. Dieser Prozess verhindert das Abhören von Zugangsdaten durch
andere Geräte.

1. **Admin generiert Token**: Ein Administrator generiert im Backend ein
   einmaliges `provisioning_token`.
2. **Client generiert Client-ID**: Beim ersten Start generiert der Locker-Client
   eine **einzigartige, zufällige Client-ID** (z.B. `random-client-xyz789`).
3. **Operator konfiguriert Client**: Der Techniker vor Ort gibt das
   `provisioning_token` in den Client ein.
4. **Client sendet Registrierungsanfrage**: Der Client verbindet sich als
   `provisioning_client` (ein User mit minimalen Rechten) und seiner
   einzigartigen Client-ID. Er sendet eine Nachricht, die seine Client-ID
   enthält, an sein Registrierungs-Topic.
   - Topic: `locker/register/das-ist-der-token-123`
   - Payload:
     ```json
     {
         "client_id": "random-client-xyz789"
     }
     ```
   - Gleichzeitig lauscht der Client auf seinem **privaten Antwort-Topic**:
     `locker/provisioning/reply/random-client-xyz789`.
5. **Backend verarbeitet Anfrage**: Der MQTT-Listener im Backend empfängt die
   Nachricht, validiert das Token, und kennt nun die Zuordnung von Token zu
   `client_id`. Es generiert eine neue `locker_uuid` und permanente
   MQTT-Zugangsdaten.
6. **Backend sendet Credentials auf privatem Kanal**: Das Backend sendet die
   neuen Zugangsdaten an das private Antwort-Topic des Clients.
   - Topic: `locker/provisioning/reply/random-client-xyz789`
   - Payload:
     ```json
     {
         "locker_uuid": "neue-uuid-abc-456",
         "mqtt_user": "neue-uuid-abc-456",
         "mqtt_password": "super-geheimes-passwort"
     }
     ```
7. **Client speichert Credentials**: Der Client empfängt die Nachricht,
   speichert die Daten persistent, trennt die Verbindung und verbindet sich mit
   seiner neuen, permanenten Identität neu. Das `provisioning_token` ist nun
   verbraucht.

## 5. Datenpersistenz mit Event Sourcing

Um eine vollständige und unveränderliche Historie aller Aktionen zu
gewährleisten, werden wir Event Sourcing implementieren. Dies ist ideal für die
Nachverfolgung und Fehlersuche. Wir verwenden dafür das Paket
`spatie/laravel-event-sourcing`.

### Kernkonzepte

- **Events**: Statt Zustände zu überschreiben, speichern wir eine Kette von
  Ereignissen (z.B. `LockerRegistered`, `DoorOpened`, `ItemReturned`).
- **Aggregate**: Ein Aggregat (z.B. `LockerAggregate`) ist ein
  Geschäftslogik-Objekt, das Befehle entgegennimmt, Regeln validiert und als
  Ergebnis neue Events aufzeichnet. Es repräsentiert eine einzelne
  Locker-Instanz.
- **Projectors**: Ein Projektor lauscht auf Events und baut daraus "Read Models"
  (normale, denormalisierte Datenbanktabellen) auf. Z.B. eine `lockers` Tabelle
  mit dem aktuellen Zustand für schnelle API-Abfragen.
- **Reactors**: Ein Reactor lauscht ebenfalls auf Events und löst nebenläufige
  Aktionen aus (Side Effects). Ein perfekter Anwendungsfall für uns: Ein
  `DoorOpened` Event löst einen Reactor aus, der einen Job in die Queue stellt,
  um den entsprechenden MQTT-Befehl zu senden.

### Beispielflow: "Tür öffnen" mit Event Sourcing

1. **API-Request**: `POST /api/lockers/{id}/open` trifft im `LockerController`
   ein.
2. **Befehl an das Aggregat**: Der Controller ruft das Aggregat auf.
   ```php
   // LockerController.php
   $lockerUuid = '...';
   LockerAggregate::retrieve($lockerUuid)
       ->openDoor($command->doorNumber, $command->transactionId)
       ->persist();
   ```
3. **Event wird gespeichert**: Das `openDoor`-Methode im `LockerAggregate`
   validiert die Geschäftsregeln (z.B. "Ist die Tür bereits offen?") und
   zeichnet ein `DoorWasOpened`-Event auf. Das Spatie-Paket speichert dieses
   Event in der `stored_events`-Tabelle.
4. **Reaktion & Projektion**:
   - Ein **`LockerReactor`** fängt das `DoorWasOpened`-Event ab und dispatcht
     einen `SendMqttOpenDoorCommand`-Job. Dieser Job sendet dann die eigentliche
     MQTT-Nachricht.
   - Ein **`LockerProjector`** fängt dasselbe Event ab und aktualisiert die
     `lockers`-Tabelle, z.B. `status` auf `opening`.

Dieser Ansatz entkoppelt die Annahme des Befehls sauber von der Ausführung der
Nebenwirkungen (MQTT-Kommunikation) und der Aktualisierung der Lese-Modelle.
