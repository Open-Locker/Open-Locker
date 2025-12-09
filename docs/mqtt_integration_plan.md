# MQTT Integrationsplan

Dieses Dokument beschreibt den Plan für die Integration von MQTT in das
Open-Locker Backend, um eine verteilte Architektur zu ermöglichen.

## 1. Komponentenübersicht

- **MQTT Broker**: Mosquitto (via Docker)
- **PHP MQTT Client**: `php-mqtt/laravel-client`
- **Backend**: Laravel `locker-backend`
- **IoT Client**: Node.js Service (Verantwortung bei anderem Teammitglied)

## 2. MQTT Broker: Mosquitto Setup

Wir verwenden Mosquitto als Docker-Container in unserer `docker-compose.yml`
und binden das Plugin **mosquitto-go-auth** ein, das die Authentifizierung und
Autorisierung über das Laravel Backend per HTTP übernimmt.

### Konfiguration (`mosquitto.conf`)

Die Konfiguration wird in einer separaten Datei gemountet und beinhaltet u.a.:

```conf
per_listener_settings true
listener 1883 0.0.0.0

allow_anonymous false

auth_plugin /mosquitto/go-auth.so
auth_opt_backends http

auth_opt_http_host app
auth_opt_http_port 8080
auth_opt_http_getuser_uri /api/mosq/auth?mosq_secret=change_me_securely
auth_opt_http_superuser_uri /api/mosq/superuser?mosq_secret=change_me_securely
auth_opt_http_aclcheck_uri /api/mosq/acl?mosq_secret=change_me_securely
auth_opt_http_with_tls false
auth_opt_http_params_mode json
```

Die HTTP-Endpunkte werden im Laravel Backend über den
`MosquittoAuthController` bereitgestellt und prüfen das Secret (`mosq_secret`) sowie:

- **Auth** (`/api/mosq/auth`) – Benutzername/Passwort
- **Superuser** (`/api/mosq/superuser`) – Admin-Rechte für spezielle Clients
- **ACL** (`/api/mosq/acl`) – Lese-/Schreibrechte auf Topics

Damit bildet Laravel auch die frühere ACL-Logik ab:

- **Backend (`laravel_backend` o.Ä.)**  
  - Darf alle relevanten Topics lesen und schreiben, z.B. `locker/#`.  
  - Kann Befehle an alle Locker senden und Status von allen empfangen.

- **Einzelner Locker-Client**  
  - Darf **nur seine eigenen Topics** verwenden, typischerweise:
    - lesen: `locker/{locker_id}/command`
    - schreiben: `locker/{locker_id}/status` und `locker/{locker_id}/state`  
  - Der effektive Namensraum wird aus der in der Datenbank hinterlegten Locker-ID
    bzw. dem MQTT-Benutzernamen abgeleitet.

- **Provisioning-Client (`provisioning_client`)**  
  - Darf Registrierungen senden, z.B. auf `locker/register/+`.  
  - Darf nur auf sein eigenes Antwort-Topic hören,
    z.B. `locker/provisioning/reply/{client_id}`.  
  - So kann ein Client nicht die Antworten eines anderen Clients abonnieren.

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
   MQTT_LAST_WILL_TOPIC=server/status
   MQTT_LAST_WILL_MESSAGE={"status": "offline"}
   ```

### Implementierungsstrategie

#### Zuverlässigkeit: QoS und "Last Will"

- **Backend zu Client (Befehle):** Alle kritischen Befehle vom Backend an die
  Clients werden mit **QoS Level 1** gesendet. Das stellt sicher, dass der
  Broker die Nachricht speichert, falls ein Client offline ist.
- **Client zu Backend (Status):** Wichtige Status-Updates von den Clients
  sollten ebenfalls mit **QoS Level 1** gesendet werden.
- **Client-Verbindungen:** Die IoT-Clients müssen sich mit der Einstellung
  **`clean_session = false`** verbinden, damit der Broker ihre Abonnements und
  verpassten Nachrichten speichert (persistente Session).
- **Backend "Last Will"**: Der Laravel-Client (sowohl Listener als auch
  Publisher) wird so konfiguriert, dass er einen "Last Will" auf dem Topic
  `server/status` mit der Nachricht `{"status": "offline"}` setzt. Dies
  informiert alle abonnierenden Clients, falls das Backend ausfällt.

#### Nachrichten Senden (Publishing)

Das Senden von Befehlen an die Locker (z.B. "Tür öffnen") erfolgt asynchron über
Laravel Queues, um die API-Antwortzeiten nicht zu beeinträchtigen.

1. **Event/Trigger**: Ein API-Aufruf von der Mobile App (z.B.
   `POST /api/lockers/{id}/open`).
2. **Job Dispatching**: Der Controller validiert die Anfrage und dispatcht einen
   Job in die Queue. `OpenCompartment::dispatch($locker);`
3. **Job Handler**: Der Job Handler nutzt die `MQTT`-Facade, um die Nachricht zu
   publizieren.

   ```php
   // App/Jobs/OpenCompartment.php
   use PhpMqtt\Client\Facades\MQTT;

   public function handle()
   {
       $topic = "locker/{$this->lockerBank->id}/command";
       $payload = json_encode([
            'action' => 'open_compartment',
            'data' => ['compartment' => $this->compartment->number]
        ]);
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
       "action": "open_compartment",
       "transaction_id": "xyz-789",
       "data": { "compartment_number": 3 }
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
       "action": "open_compartment",
       "status": "success",
       "transaction_id": "xyz-789",
       "message": "Compartment opened successfully."
    }
    ```

- **Antwort auf `open_door` (Fehler):**
  - Topic: `locker/uuid-123/status`
  - Payload:
    ```json
    {
       "event": "action_failed",
       "action": "open_compartment",
       "status": "error",
       "transaction_id": "xyz-789",
       "error_code": "DOOR_JAMMED",
       "message": "Could not open compartment, mechanism is jammed."
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
          "compartment_number": 3,
          "timestamp": "2023-10-27T10:00:00Z"
       }
    }
    ```

#### 4.3 Zustand (Client sendet)

Für regelmäßige "Heartbeats" oder Telemetriedaten.

- **Regelmäßiger Heartbeat:**
  - Topic: `locker/{uuid}/state`
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

#### 4.4 "Last Will" - Client geht offline

Das "Last Will and Testament"-Feature von MQTT wird genutzt, um unerwartete
Verbindungsabbrüche zu melden.

- **Konfiguration**: Der Client setzt beim Verbindungsaufbau seinen "letzten
  Willen" (siehe oben).
- **Topic**: `locker/{locker_uuid}/state`
- **Payload**:
  ```json
  {
     "event": "connection_lost",
     "status": "offline",
     "timestamp": "..."
  }
  ```
- **Funktionsweise**: Wenn der Broker erkennt, dass der Client die Verbindung
  unplanmäßig verloren hat, publiziert der Broker diese Nachricht automatisch im
  Namen des Clients auf das angegebene Topic. Das Backend kann darauf lauschen
  und den Status des Schranks auf "unreachable" setzen.

#### 4.5 Registrierungsprozess (Pre-Provisioning Workflow)

Der Client wird im Backend vor-registriert ("pre-provisioned"), um maximale
Sicherheit und Kontrolle zu gewährleisten.

1. **Admin legt LockerBank an**: Ein Administrator erstellt im Filament-Backend
   eine neue `LockerBank` und die zugehörigen `Compartment`s. Das System
   generiert automatisch ein einmaliges `provisioning_token` für diese
   `LockerBank`.
2. **Operator erhält Token**: Der Admin gibt dieses Token an den Techniker
   weiter, der den IoT-Client installiert.
3. **Client startet**: Der Client startet, generiert eine einmalige Client-ID
   und wird vom Techniker mit dem `provisioning_token` konfiguriert.
4. **Client meldet sich**: Der Client verbindet sich als `provisioning_client`
   und sendet seine `client_id` an das Registrierungs-Topic, das das Token
   enthält.
   - Topic: `locker/register/das-ist-der-token-123`
   - Payload: `{"client_id": "random-client-xyz789"}`
   - Gleichzeitig lauscht der Client auf seinem privaten Antwort-Topic:
     `locker/provisioning/reply/random-client-xyz789`.
5. **Backend verknüpft und provisioniert**:
   - Der `MqttListen`-Befehl empfängt die Anfrage.
   - Er sucht in der Datenbank nach der `LockerBank`, die zu dem
     `provisioning_token` gehört.
   - Er generiert permanente MQTT-Zugangsdaten (Username = `locker_bank_uuid`,
     Passwort = sicheres, zufälliges Passwort).
   - Er speichert den neuen MQTT-User im `password.conf` des Brokers.
6. **Backend sendet Credentials (oder Ablehnung)**:
   - **Bei Erfolg**: Das Backend sendet die neuen Zugangsdaten an den privaten
     Antwortkanal des Clients.
     - Topic: `locker/provisioning/reply/{unique_client_id}`
     - Payload:
       `{"status": "success", "data": {"mqtt_user": "...", "mqtt_password": "..."}}`
   - **Bei Fehler** (z.B. Token ungültig): Das Backend sendet eine
     Fehlermeldung.
     - Topic: `locker/provisioning/reply/{unique_client_id}`
     - Payload:
       ```json
       {
          "status": "error",
          "message": "Invalid or expired provisioning token."
       }
       ```
7. **Client ist online**: Bei Erfolg speichert der Client die Zugangsdaten,
   verbindet sich neu und ist einsatzbereit. Bei Fehler kann der Client eine
   entsprechende Meldung anzeigen.

## 5. Datenpersistenz mit Event Sourcing

Um eine vollständige und unveränderliche Historie aller Aktionen zu
gewährleisten, werden wir Event Sourcing implementieren. Dies ist ideal für die
Nachverfolgung und Fehlersuche. Wir verwenden dafür das Paket
`spatie/laravel-event-sourcing`.

### Kernkonzepte

- **Events**: `LockerBankProvisioned`, `CompartmentOpeningRequested`,
  `CompartmentOpened`
- **Aggregate**: Das primäre Aggregat ist `LockerBankAggregate`. Es
  repräsentiert einen ganzen Schrank.
- **Projectors**: Ein `LockerBankProjector` erstellt und aktualisiert die
  Lese-Modelle für `locker_banks` und `compartments`.
- **Reactors**: Ein `MqttReactor` lauscht auf Events (z.B.
  `CompartmentOpeningRequested`) und löst die MQTT-Kommunikation aus.

### Beispielflow: "Fach öffnen" mit Event Sourcing

1. **API-Request**: `POST /api/compartments/{id}/open` trifft ein.
2. **Befehl an das Aggregat**: Der Controller lädt das zugehörige
   `LockerBankAggregate` und sendet einen Befehl.

```php
// CompartmentController.php
$compartment = Compartment::findOrFail($id);
LockerBankAggregate::retrieve($compartment->lockerBank->id)
    ->requestToOpenCompartment($compartment->uuid, $request->user()->id)
    ->persist();
```

3. **Event wird gespeichert**: Das Aggregat validiert die Anfrage und zeichnet
   ein `CompartmentOpeningRequested`-Event auf.
4. **Reaktion**: Ein **`MqttReactor`** fängt das Event ab und dispatcht einen
   Job, der den `open_compartment`-Befehl an die
   `lockerbank/{uuid}/command`-Topic sendet.

Dieser Ansatz entkoppelt die Annahme des Befehls sauber von der Ausführung der
Nebenwirkungen (MQTT-Kommunikation) und der Aktualisierung der Lese-Modelle.
