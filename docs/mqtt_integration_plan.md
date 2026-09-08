# MQTT-Integrationsplan

Dieses Dokument hält nur den Umsetzungsstand und verbleibende Arbeiten fest. Der
verbindliche MQTT-Vertrag steht ausschließlich in
[`asyncapi/mqtt.yaml`](asyncapi/mqtt.yaml) sowie den referenzierten Schemas und
Beispielen. Topic-, Payload-, QoS- und Retain-Details werden hier bewusst nicht
dupliziert.

## Komponenten und Grenzen

- Mosquitto vermittelt Nachrichten und nutzt das Laravel-Backend für
  Authentifizierung und ACL-Prüfung.
- Das Laravel-Backend sendet über typisierte Publisher und verarbeitet
  eingehende Nachrichten über spezialisierte Handler.
- Der Node.js-`locker-client` verbindet MQTT mit der seriellen
  Modbus-Kommunikation am Standort.
- Die Mobile App verwendet REST und Laravel Reverb. Sie verbindet sich nicht
  direkt mit MQTT.

## Implementiert

- Provisioning mit einmaligem Token, isoliertem Reply und persistierten
  Geräte-Credentials.
- Laufzeit-Commands für das Öffnen eines Fachs und das Anwenden der
  serververwalteten Hardware-Konfiguration.
- Transaktionsgebundene Responses mit persistenter Deduplication und
  Wiederherstellung nicht sicher zugestellter Antworten.
- Persistente MQTT-Sessions und unbegrenzter Reconnect im `locker-client`.
- Heartbeats, Connection-/LWT-Signale und retained Full Snapshots der
  Fachzustände.
- Physische Öffnungsergebnisse und unaufgeforderte Türöffnungen als
  Device-Events, getrennt von der Command-Bestätigung.
- Backend-Verarbeitung über Stored Events, Projectors und Reactors; Reverb-
  Broadcasts sind Side Effects und nicht die Source of Truth.
- JSON-Schema-Contract-Tests für Backend und `locker-client`. Änderungen an der
  AsyncAPI-Spezifikation starten die zugehörigen CI-Testläufe.

Die maßgeblichen Implementierungseinstiege sind:

- `locker-backend/app/Mqtt/Publishers/`
- `locker-backend/app/Mqtt/Handlers/`
- `locker-client/src/adapters/mqtt/`
- `locker-client/src/application/`

## Noch offen oder bewusst begrenzt

- Das Backend validiert und protokolliert Connection-/LWT-Signale derzeit, leitet
  daraus aber keinen fachlichen Online-/Offline-Status ab. Heartbeat-Timeouts
  bleiben dafür maßgeblich.
- Weitere mögliche Device-Events wie QR-Scan oder Manipulationserkennung sind
  nicht implementiert. Sie sind keine Zusage des aktuellen Vertrags.
- Änderungen an Topics oder Payloads müssen zuerst als explizite
  Architekturentscheidung und anschließend in der kanonischen AsyncAPI-
  Spezifikation erfolgen. Dieses Dokument beschreibt keine Vertragsänderung.

## Weiterführende Entscheidungen

- `docs/adr/0014-locker-client-mqtt-session-and-reconnect.md`
- `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
- `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
- `docs/adr/0017-split-mqtt-state-topics-by-lifecycle.md`
- `docs/adr/0040-separate-command-acknowledgement-from-door-open-detection.md`
- `docs/adr/0058-backend-managed-rs485-locker-board-profile.md`
