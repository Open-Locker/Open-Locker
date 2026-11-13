---
title: Bench-Test
description: Controller, Bus und ein Schloss prüfen, bevor das Backend beteiligt ist.
sidebar:
  order: 5
---

Dieser Test zeigt, dass der ESP32 das Panel erreicht und ein Schloss freigeben kann, ganz ohne WLAN, Broker
oder Backend. Er gehört vor die Inbetriebnahme, weil sich ein hier gefundener Fehler viel leichter eingrenzen
lässt.

## Was der Bench-Test beweist

Eine Einheit ohne Netzwerkeinstellungen hat keinen Broker, also vertritt ihn die USB-Konsole. Das ist der
Bench-Modus. Kommandos werden eingetippt, und die Einheit gibt das, was sie veröffentlichen würde, in Zeilen aus,
die mit `out` beginnen.

- Benötigt wird ein Release- oder Development-Build. Ein Production-Build hat weder `unlock` noch `command`.
- Der Bench-Modus endet, sobald Netzwerkeinstellungen gespeichert sind.
- Das ist ein kleiner Teil der Hardwaregruppen A und B und kein Ersatz für
  [die vollständigen Prüfungen](/de/dokumentation/esp32-hardware-acceptance/).

:::caution[Das gibt ein Schloss frei]
Am Prüfaufbau bleiben und die Tür von Kanal 1 freihalten.
:::

## Konsole öffnen

```bash
idf.py -p COM3 monitor
```

Die Konsole läuft mit 115200 Baud. Die Eingabe wird nicht angezeigt, der Bildschirm bleibt also bis zur Antwort
leer. Jede Antwort ist eine Zeile, die mit `ok` oder `error` beginnt. Außerdem erscheinen alle 15 Sekunden
`out heartbeat ...`-Zeilen, die sich ignorieren lassen.

## Controller prüfen

1. `status` eingeben. Ohne gespeicherte Netzwerkeinstellungen sieht die Antwort so aus:

   ```text
   ok bus=disconnected enrolled=0 pending=0 configured=0
   ```

2. `self-test` eingeben. Das prüft den Frame-Encoder und sendet nichts an das Panel:

   ```text
   ok self-test passed
   ```

3. `diagnostics` eingeben. Dort `unlock_transmissions` beachten, das jede Freigabe zählt, die seit dem Boot die
   Leitung erreicht hat. Der Wert sollte `0` sein.

## Bus prüfen

1. Panelversorgung einschalten.
2. `query 1` eingeben.

   Es erscheint `ok doors=`, dann 32 Zeichen, eines pro Adresse ab 0: `c` geschlossen, `o` offen und `?` unbekannt.
   Adresse 0 ist Kanal 1. Kanäle, die das Panel nicht hat, lesen sich als `?`.

   Bei `error query no_valid_reply` siehe
   [Fehlerbehebung](/de/dokumentation/esp32/troubleshooting/#symptom-ursache-lösung).

3. `status` erneut ausführen. Jetzt sollte `bus=connected` erscheinen.

## Ein Schloss freigeben

1. Prüfen, dass die Tür von Kanal 1 frei ist.
2. `unlock 1 0` eingeben, das bedeutet Board 1, Adresse 0. Das Schloss gibt einmal frei, und es erscheint:

   ```text
   ok unlock acknowledged
   ```

3. `diagnostics` eingeben. `unlock_transmissions` sollte um eins höher sein.
4. Die Tür von Hand öffnen und schließen und jedes Mal `query 1` eingeben. Das erste Zeichen sollte von `c` auf
   `o` und zurück wechseln.

`unlock` wird nie wiederholt. Bei `error unlock outcome_unknown` hat sich das Schloss vielleicht bewegt, vielleicht
auch nicht. Vor einem neuen Versuch also nachsehen.

## Echtes Kommando ausprobieren

Der Konsolenbefehl `command` übergibt der Anwendung eine MQTT-Nutzlast, genau wie es der Broker tun würde. Das
testet auch die Zuordnung und das Journal.

1. Die Zuordnung senden. Diese hat zwei Fächer auf Board 1, ihr Hash stimmt, also als eine Zeile einfügen:

   ```text
   command {"message_id":"bench-msg-1","transaction_id":"bench-cfg-1","action":"apply_config","timestamp":"2026-04-14T19:31:00Z","data":{"config_hash":"3306f9a74f407226b567536b577fa7dfd460a674850ce9d1818aabe94f55ab62","heartbeat_interval_seconds":15,"adapter_type":"rs485_lock_board","feedback_type":"door_closing","compartments":[{"compartment_number":1,"slaveId":1,"address":0},{"compartment_number":2,"slaveId":1,"address":1}]}}
   ```

   Es erscheinen `ok command queued`, eine `out response`-Zeile mit `"result":"success"` und demselben Hash als
   `applied_config_hash`, sowie eine `out snapshot`-Zeile.

2. Fach 1 öffnen. Das Schloss gibt einmal frei:

   ```text
   command {"message_id":"bench-msg-2","transaction_id":"bench-open-1","action":"open_compartment","timestamp":"2026-04-14T19:32:00Z","data":{"compartment_number":1}}
   ```

   Es erscheinen `ok command queued` und eine `out response`-Zeile mit `"result":"success"`. Die Bench-Ausgabe trägt
   einen Zeitstempel von 1970, weil es ohne Netzwerk keine Uhrzeit gibt.

3. Dieselbe Zeile noch einmal senden. Die Einheit gibt die gespeicherte Antwort aus, und das Schloss bewegt sich
   nicht, weil eine wiederholte `transaction_id` nie zweimal läuft. Prüfen, dass sich `unlock_transmissions` in
   `diagnostics` nicht geändert hat.

Für ein erneutes Öffnen beide IDs ändern. Die Zuordnung übersteht einen Neustart, und die Inbetriebnahme hängt nicht
davon ab: Das Backend sendet nach der Registrierung seine eigene Zuordnung.

## Wenn es nicht funktioniert hat

**`error invalid command`**: Die Einheit kennt das Wort nicht, oder es ist ein Production-Build.

**`error unlock disabled` oder `error command not accepted`**: Die Einheit hat Netzwerkeinstellungen, Kommandos kommen
also nur noch vom Broker. `error command not accepted` bedeutet auch, dass sich das JSON nicht parsen ließ.

**Eine `response`-Zeile mit `"result":"error"`**: Der `error_code` in der Zeile nennt den Grund. Siehe die
[Fehlercodes](/de/dokumentation/esp32/reference/#fehlercodes-für-kommandos).

## Weiter

[Einheit in Betrieb nehmen](/de/dokumentation/esp32/commissioning/).
