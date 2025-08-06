# Implementierungs-To-Do-Liste

Dies ist eine Übersicht der anstehenden Aufgaben für den Architektur-Umbau.

- [x] ~~Mosquitto-Service zu `docker-compose.yml` hinzufügen und
      Konfigurationsdateien anlegen.~~
- [x] ~~`php-mqtt/laravel-client` im Backend installieren und konfigurieren.~~
- [x] ~~`spatie/laravel-event-sourcing` installieren und Migrations ausführen.~~
- [x] ~~Backend refaktorisieren, um die direkte Modbus-Implementierung zu
      entfernen (z.B. `LockerService`, `LockerPollStatus`-Command).~~
- [ ] **(In Arbeit)** Backend-Logik für den Registrierungsprozess implementieren
      (Token-Generierung, MQTT-Listener).
- [ ] Event-Sourcing-Komponenten für die Registrierung erstellen (Aggregate,
      Event, Projector).
- [ ] "Tür öffnen"-Funktion als End-to-End-Beispiel implementieren
      (API-Endpoint, Event-Sourcing-Flow, MQTT-Publishing).
- [ ] MQTT-Listener erweitern, um auf Status-Antworten
      (`action_completed`/`action_failed`) zu lauschen und den Zustand zu
      aktualisieren.
- [ ] Cursor-Regeln (.cursor/rules) an die neue MQTT- und
      Event-Sourcing-Architektur anpassen.
