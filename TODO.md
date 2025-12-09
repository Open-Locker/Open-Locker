# Implementierungs-To-Do-Liste

Dies ist eine Übersicht der anstehenden Aufgaben für den Architektur-Umbau.

- [x] Mosquitto-Service zu `docker-compose.yml` hinzufügen und Konfiguration
      (acl/password) einbinden
- [x] `php-mqtt/laravel-client` im Backend installieren und konfigurieren
      (mehrere Verbindungen: `default`, `publisher`, `provisioning`)
- [x] `spatie/laravel-event-sourcing` installieren, konfigurieren
      (`config/event-sourcing.php`, eigene `events`-Queue) und Migrations
      ausführen
- [x] Umstieg auf MySQL (statt SQLite), UUID-FKs korrigieren (`foreignUuid`)
- [x] Dedizierten `event-worker` in `docker-compose.yml` hinzufügen (serielle
      Verarbeitung der Events)
- [x] MQTT-Tools in App-Image installieren (`mosquitto` + `mosquitto-clients`)
- [x] MQTT-Provisionierung als Event-Sourcing-Flow implementieren:
  - [x] `MqttListen` (Command) empfängt Registrierung
  - [x] `LockerBankAggregate::provision()` zeichnet Events auf
  - [x] `LockerWasProvisioned` / `LockerProvisioningFailed` Events
  - [x] `LockerBankProjector` setzt `provisioned_at`
  - [x] `MqttReactor` erstellt Broker-User + sendet Credentials
  - [x] Publisher-Verbindung für Reactor, um Client-ID-Konflikte zu vermeiden
- [x] Sichere ACL für Provisioning umsetzen (Mosquitto `%c`-Pattern für `reply`)
- [x] End-to-End-Testtools:
  - [x] Artisan: `mqtt:test-provisioning` (listen + publish)

Nächste Schritte

- [ ] Feature: „Fach öffnen“ (Start über Filament)
  - [ ] Filament-Action (z.B. in `LockerBankResource` →
        `CompartmentsRelationManager`): `open_compartment`
  - [ ] Action triggert Aggregate-Event `CompartmentOpeningRequested`
  - [ ] Reactor: Publish an `locker/{locker_bank_uuid}/command` (QoS 1)
  - [ ] Projector/Read-Model-Update (optional)
  - [ ] Tests (Feature + E2E via mosquitto CLI/Artisan)
- [ ] Heartbeat-Workflow implementieren (siehe `docs/mqtt_integration_plan.md`)
  - [ ] Listener für `locker/{uuid}/state` (Heartbeat/Telemetrie)
  - [x] Events/Projector: `HeartbeatReceived` → `last_seen`/`online_status`
  - [x] Extend Tests: nach erfolgreicher Provisionierung mit den Credentials
        verbinden und Heartbeat senden/validieren
- [ ] Status-Updates (Aktionen) empfangen und verarbeiten
  - [ ] Listener für `locker/{uuid}/status` (z.B.
        `action_completed`/`action_failed`)
  - [ ] Events/Projector für Statushistorie
  - [ ] Tests (Success/Failure-Szenarien)
- [ ] Fehlerpfade robuster machen
  - [ ] `MqttReactor`: bei Fehler zusätzliches Event dispatchen (siehe TODO im
        Code)
  - [ ] Timeouts/Retry-Strategie dokumentieren
- [ ] Sicherheit & Ops
  - [ ] Dateirechte für `docker/mosquitto/config/*.conf` auf 0700 setzen
        (Mosquitto Warnungen)
  - [ ] Doku der ACL-Sicherheitsregeln (`%c`-Pattern) und Testfälle
- [ ] Developer-UX
  - [ ] README/Docs für E2E-Tests (Artisan + mosquitto CLI Beispiele)
  - [ ] Cursor-Regeln anpassen (MQTT + Event Sourcing)

Offene Code-TODOS

- [x] `app/Reactors/MqttReactor.php`: „TODO: Dispatch a new event to record the
      failure after the initial success event.“
