---
title: Hardware-Abnahme
description: Die Bench-Prüfungen der ESP32-Firmware ausführen und das Ergebnis festhalten.
---

Ein Firmware-PR wird erst gemergt, wenn seine Hardwareprüfungen bestanden sind. `scripts/hwtest.py` führt sie in einer Sitzung aus und schreibt den Nachweis, den CI prüft. `python scripts/hwtest.py list` zeigt den aktuellen Stand jeder Prüfung.

Die Prüfungen heißen in den ADRs und im Testkatalog `HW-A1`. Das Werkzeug lässt das Präfix weg: `--only A,B3`, und `A1` in seiner Ausgabe.

Den aktuellen Stand der Gruppen zeigt [Controller wählen](/de/dokumentation/esp32/#reifegrad-der-esp32-firmware-bench).

## Vorbereitung

- Der [Prüfaufbau](/de/dokumentation/esp32/parts/): Board, Wandler, Panel und ein Schloss auf Kanal 1.
- Eine geflashte, gelöschte Einheit ohne Einstellungen. `hwtest.py` braucht den Bench-Modus, also einen Release- oder Development-Build.
- `pip install -r requirements.txt` in `locker-firmware/`.
- Keine uncommitteten Änderungen in `locker-firmware/`. Das Werkzeug verweigert sonst den Lauf, weil der Nachweis den committeten Baum benennt.
- Jemand am Prüfaufbau. Mit **Löst aus** markierte Prüfungen geben das Schloss frei.

## Prüfungen ausführen

```bash
python scripts/hwtest.py run --port COM3 --image build/locker-firmware.bin
```

`--image` ist das geflashte `.bin`, und es muss genau dieses sein: Sein SHA-256 steht im Nachweis. Bei einem signierten Image `build/locker-firmware-signed.bin` angeben. Das Werkzeug steuert die USB-Konsole und fragt nach den physischen Schritten. Vor jeder Prüfung, die das Schloss freigibt, `go` oder `skip` eingeben.

| Option | Verwendung |
| --- | --- |
| `--only A,B3` | Nur diese Gruppen und Prüfungen |
| `--resume` | Nur was noch fehlt, fehlgeschlagen ist oder nicht lief |
| `--failed` | Nur was fehlgeschlagen ist |
| `--operator "Name"` | Wer ausgeführt hat, statt der Git-Identität |
| `--board 1`, `--address 0` | Das Panel und der geprüfte Kanal |

`python scripts/hwtest.py list` zeigt jede Prüfung mit ihrem Status. Strg+C beendet einen Lauf, die bis dahin abgeschlossenen Prüfungen bleiben im Nachweis.

## Regeln für einen Lauf

- Die Gruppen der Reihe nach ausführen. Ein Fehlschlag macht spätere Ergebnisse unzuverlässig: anhalten, beheben und bei der fehlgeschlagenen Gruppe neu beginnen.
- Jede Behebung ist ein Commit im selben PR und muss die [Gates](/de/dokumentation/esp32-development/#gates-ausführen) bestehen.
- Eine nicht ausgeführte Prüfung wird als nicht ausgeführt markiert, nie als bestanden.
- Für den Merge zählt nur ein Lauf gegen den letzten Commit des PR.

## Der Nachweis, den CI prüft

`run` schreibt nach jeder Prüfung `locker-firmware/hardware-record.json`. Die Datei committen. `python scripts/hwtest.py check` braucht keine Hardware und schlägt fehl, wenn:

- der Nachweis fehlt oder einen anderen Baum benennt. Der Baum-Hash deckt jede committete Datei in `locker-firmware/` ab, außer Markdown und dem Nachweis selbst, jede Firmware-Änderung braucht also einen neuen Lauf;
- eine Prüfung fehlgeschlagen ist, fehlt oder nicht lief, ohne zurückgestellt zu sein;
- Bedienperson, Datum oder Firmware-SHA-256 fehlen.

Der Nachweis ist die Aussage der Bedienperson, kein Beweis. Er verhindert nur, dass eine Änderung ohne Lauf oder mit fehlgeschlagenem Lauf hineinkommt.

## Die Prüfungen

**auto** bedeutet, das Werkzeug steuert die Prüfung und fragt nur, was es nicht sehen kann. **manuell** bedeutet, die Prüfung läuft anderswo, und das Ergebnis wird gemeldet. **zurückgestellt** bedeutet, sie wird mit Grund als nicht ausgeführt festgehalten und blockiert den Nachweis nicht. Die Schritte zu jeder manuellen Prüfung stehen unter [Manuelle Prüfungen Schritt für Schritt ausführen](#manuelle-prüfungen-schritt-für-schritt-ausführen).

### Gruppe A: Serieller Bus und Panel

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-A1 | Im Leerlauf dreimal aus- und einschalten: Der Freigabezähler bleibt 0, und das Schloss bewegt sich nicht | auto |
| HW-A2 | `self-test` besteht | auto |
| HW-A3 | `query 1` liefert einen gültigen Frame | auto |
| HW-A4 | **Löst aus.** Abfrage mit geschlossener, offener und wieder geschlossener Falle; Bit und Polarität festhalten | auto |
| HW-A5 | Panel stromlos: senden, und nichts kommt zurück | auto |
| HW-A6 | **Löst aus.** `unlock 1 0` gibt das Schloss genau einmal frei | auto |
| HW-A7 | Antwortzeiten von Abfragen und einer Freigabe liegen innerhalb der Frist | auto |
| HW-A8 | Panelstrom weg: Der Bus trennt sich und erholt sich ohne Neustart | auto |
| HW-A9 | 200 Freigaben und 200 Abfragen: längste Antwort unter der Frist, Settle-Zeit darüber | zurückgestellt |

### Gruppe B: Kommandos und Journal

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-B1 | **Löst aus.** `apply_config`, dann ein Öffnen: Das Schloss gibt einmal frei, und die Antwort wird ausgegeben | auto |
| HW-B2 | Doppeltes Öffnen, mit derselben und einer neuen Message-ID: Der Zähler ändert sich nicht | auto |
| HW-B3 | Neustart: Die Zuordnung gilt weiter | auto |
| HW-B4 | **Löst aus.** Fünfmal Strom direkt nach einem Öffnen trennen: ein gespeichertes Ergebnis und keine Freigabe beim Boot | auto |
| HW-B5 | Stromausfall bei Claim, Senden, Completion und PUBACK | zurückgestellt |
| HW-B6 | 600 Kommandos: Das Journal kompaktiert, ohne eines abzulehnen, die neuesten 256 werden wiedergegeben, ein älteres läuft als neu | zurückgestellt |

### Gruppe C: Netzwerk, MQTT und Enrollment

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-C1 | Eine frische Einheit gegen das echte Backend registrieren: Der Schrank zeigt sich als verbunden | manuell |
| HW-C2 | Ein Broker mit Zertifikat für den falschen Hostnamen oder selbstsigniert wird abgelehnt | zurückgestellt |
| HW-C3 | `broker_check.py` ohne `--actuate`: `apply_config` wird mit passendem Hash bestätigt | manuell |
| HW-C4 | **Löst aus.** Ein Öffnen aus dem Admin-Panel gibt das Schloss einmal frei | manuell |
| HW-C5 | **Löst aus.** `broker_check.py --actuate`: Ein Öffnen und zwei Duplikate erhöhen den Zähler um 1 | manuell |
| HW-C6 | Access Point 1 Minute aus, dann der Broker: Die Einheit verbindet sich neu, und eine offene Antwort wird zugestellt | manuell |
| HW-C7 | Ungültiges Token: abgelehnt ohne Wiederholung. Nach einem Provisionierungs-Reset und einem frischen Token registriert sie sich | manuell |
| HW-C8 | Strom trennen: Das Backend zeigt den Schrank offline | manuell |
| HW-C9 | Nach HW-C6 zeigt `diagnostics` den geringsten freien Heap und die Stack-Reserve des Owner-Tasks. Beides festhalten | manuell |

Die Broker-Prüfungen ausführen:

```bash
BROKER_USER=... BROKER_PASSWORD=... python scripts/broker_check.py \
  --url mqtts://broker.example:8883 --locker-uuid <uuid> --compartments 1:1:0 --feedback door_closing --heartbeat 15
```

Für HW-C5 `--actuate --compartment 1` ergänzen und `unlock_transmissions` in `diagnostics` davor und danach vergleichen. Die Zugangsdaten kommen aus der Umgebung und erscheinen so nicht in einer Prozessliste.

### Gruppe D: Zustand und Türereignisse

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-D1 | Tür von Hand: Admin-Panel und App folgen innerhalb von zwei Sekunden | manuell |
| HW-D2 | **Löst aus.** Über das Backend öffnen und die Tür ziehen: `opened` kommt an | manuell |
| HW-D3 | **Löst aus.** Über das Backend öffnen und die Tür halten: `door_jammed` kommt an | manuell |
| HW-D4 | Tür ohne Kommando aufdrücken: Ein Ereignis für unbefugtes Öffnen kommt an | manuell |
| HW-D5 | Panelstrom weg: Der Heartbeat meldet getrennt, Zustände werden unbekannt, und beides erholt sich | manuell |
| HW-D6 | Broker-Neustart bei offener Tür: kein Türereignis beim Neuverbinden | zurückgestellt |

### Gruppe E: USB-Inbetriebnahme

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-E1 | **Löst aus.** Eine gelöschte Einheit mit `commission.py` bis zum Öffnen in Betrieb nehmen | manuell |
| HW-E2 | Falsche WLAN-Zugangsdaten, über USB ohne Reset korrigiert: Die Identität bleibt | manuell |
| HW-E3 | Eine registrierte Einheit in ein anderes Netz umziehen: Identität, Zuordnung und Journal bleiben | zurückgestellt |
| HW-E4 | Zehnmal USB während des Speicherns trennen: alte oder neue Einstellungen, nie eine Mischung | zurückgestellt |
| HW-E5 | Reset: Alte Zugangsdaten schlagen fehl, und ein frisches Token registriert die Einheit | manuell |

### Gruppe F: Inbetriebnahme über das Setup-Netzwerk

Benötigt eine Einheit mit Setup-Secrets, einen Laptop mit `commission.py --softap` aus demselben Build und für HW-F6 einen Production-Build.

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-F1 | **Löst aus.** Eine gelöschte Einheit über das Setup-Netzwerk bis zum Öffnen aus dem Admin-Panel in Betrieb nehmen | manuell |
| HW-F2 | Falsches WLAN-Passwort: Sie versucht es weiter, 10 s Tasterdruck setzt sie zurück, und dasselbe unbenutzte Token registriert sie | manuell |
| HW-F3 | Registrierte Einheit: Access Point und sein Port sind nicht erreichbar | manuell |
| HW-F4 | Taster: unter 10 s nichts, 10 s setzt zurück, beim Einschalten gehalten nichts, ein festsitzender Jumper nichts | manuell |
| HW-F5 | Zweite Station abgelehnt, 10-Minuten-Fenster, ein falsches Setup-Passwort schlägt fehl, und 5 Fehlschläge schließen das Netzwerk | manuell |
| HW-F6 | Ein Production-Build lehnt `configure`, `unlock`, `command` und `reset-network` über USB ab | manuell |
| HW-F7 | Ein Mitschnitt zeigt weder WLAN-Passwort noch Token im Klartext | manuell |
| HW-F8 | Ein Token älter als 24 Stunden wird abgelehnt, ein frisches funktioniert | manuell |

### Gruppe G: Dauerbetrieb und Rückgabe

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-G1 | **Löst aus.** 72 Stunden mit einem Öffnen alle zehn Minuten: keine verpasste Antwort, kein Reset, stabiler Heap | zurückgestellt |
| HW-G2 | Den Schrank an den Pi Client zurückgeben | zurückgestellt |

### Gruppe H: Over-the-Air-Updates

Benötigt eine per USB mit einem signierten Image geflashte Einheit, ein Backend mit Firmware-Releases und ein zweites signiertes Release. Läuft, nachdem die Gruppen A bis E und G bestanden sind.

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-H1 | Leeres Board mit dem signierten Image flashen: Es registriert sich, dann erneut HW-A1, HW-A6, HW-C4, HW-D2 und HW-D4 | manuell |
| HW-H2 | Eine untätige Einheit über das Backend aktualisieren: Sie startet in die neue Version, und das Backend zeigt sie | manuell |
| HW-H3 | **Löst aus.** Ein Öffnen während eines Updates und eines im Probefenster eines Rollbacks: einmal behandelt, nie verloren | manuell |
| HW-H4 | Eine fehlerhafte Signatur und ein mit anderem Schlüssel signiertes Image werden abgelehnt: Die alte Version läuft weiter | manuell |
| HW-H5 | Abgeschnittenes Image, dann WLAN während des Downloads weg: Die alte Version läuft, und das Backend zeigt den Fehler | manuell |
| HW-H6 | Ein gültiges Image, das den Broker nicht erreicht: nach dem Probefenster zurück zur alten Version | manuell |
| HW-H7 | Zehnmal Strom während des Image-Schreibens trennen: Sie bootet immer ein gültiges Image | manuell |
| HW-H8 | Nach einem Update und einem Rollback: Identität, Zuordnung und Journal intakt, und keine Freigabe ausgelöst | manuell |
| HW-H9 | Geringster freier Heap während eines Downloads bei offenem MQTT lässt die Stack-Reserve aus HW-C9 | manuell |
| HW-H10 | Der Bootloader bootet eine App, die mit dem nächsten ESP-IDF-Patch-Release gebaut wurde | manuell |
| HW-H11 | Ein Image in der Probephase zurückrollen, nachdem es Kommandos behandelt hat: Das vorherige Image liest das Journal | manuell |

HW-H1 sagt „Bootloader mit Rollback“, nicht Signaturprüfung, weil der Bootloader bei ausgeschaltetem Hardware-Secure-Boot beim Booten keine Signaturen prüft. Die laufende App prüft sie, wenn ein Update ankommt, und das testet HW-H4.

## Manuelle Prüfungen Schritt für Schritt ausführen

Wenn `hwtest.py` `(do it with the backend and broker, then report)` ausgibt, das Werkzeug an seiner Eingabeaufforderung lassen und die Schritte unten in einem zweiten Terminal ausführen. Danach **Pass** oder **Fail** wählen und das Gesehene mit Zeiten und Zahlen als Notiz eintragen.

Die Gruppen F und H sind noch nicht vollständig an einem Prüfaufbau gelaufen, diese Schritte stammen also aus dem Code von Firmware und Backend. Passt ein Schritt nicht zum Gesehenen, das in der Notiz festhalten.

### Manuelle Prüfungen vorbereiten

Firmware-Befehle in `locker-firmware/` und Backend-Befehle in `locker-backend/` ausführen. Die Beispiele gehen vom lokalen Docker-Backend aus, dessen Log `storage/logs/laravel.log` ist. `COM3` durch den eigenen Port ersetzen, etwa `/dev/ttyACM0`, und `<uuid>` durch die ID in der Adresse der Bearbeitungsseite des Schranks. Hat der Docker-Stack einen eigenen Projektnamen, `-p <name>` bei den `docker compose`-Befehlen ergänzen.

Die Gruppen A und B lassen die Einheit im Bench-Modus, ab Gruppe C braucht es aber eine in Betrieb genommene Einheit. Eine registrierte Einheit lehnt `unlock` und `command` ab.

**Konsole öffnen.** `hwtest.py` dabei weiterlaufen lassen. An einer Eingabeaufforderung liest es den Port nicht, und der offene Port verhindert, dass das Öffnen der Konsole die Einheit neu startet. Eingaben werden nicht angezeigt. Nach jedem Befehl Enter drücken und mit `Strg+]` beenden.

```bash
python -m serial.tools.miniterm --dtr 0 --rts 0 COM3 115200
```

**Prüfen, dass die Einheit nicht neu gestartet hat.** `uptime_seconds` sollte weiter steigen:

```bash
grep -o 'uptime_seconds[^,]*' storage/logs/laravel.log | tail -3
```

**Auf Online warten.** Vom Registrieren bis **Online** im Admin-Panel kann etwa eine Minute vergehen. Vor einer Broker-Prüfung darauf warten.

### Gruppe C Schritt für Schritt

#### HW-C1: Frische Einheit registrieren

1. Die Einheit [über USB](/de/dokumentation/esp32/commissioning/#über-usb-release--und-development-builds) in Betrieb nehmen.
2. **Schließfachschränke** im Admin-Panel öffnen.

**Bestanden:** Der **Status** des Schranks zeigt innerhalb von etwa einer Minute Online.

#### HW-C3: Konfiguration über den Broker anwenden

Das sendet der Einheit über den Broker eine Konfiguration und prüft, ob sie dieselbe gespeichert hat. Kein Schloss bewegt sich, aber Zuordnung und Heartbeat-Intervall der Einheit werden ersetzt. `--compartments` muss also zur Verdrahtung passen.

1. Die [Broker-Prüfung](#gruppe-c-netzwerk-mqtt-und-enrollment) ohne `--actuate` ausführen. Beim lokalen Backend sind `BROKER_USER` und `BROKER_PASSWORD` die Werte `MQTT_USERNAME` und `MQTT_PASSWORD` aus `locker-backend/.env`, und die URL ist `mqtt://<Adresse des eigenen PCs>:1883`.

**Bestanden:** Die Ausgabe endet mit `PASS apply_config acknowledged with the matching hash` und `PASSED`.

| Ausgabe | Bedeutung |
| --- | --- |
| `FAIL apply_config: no response` | Die Einheit ist offline, oder die UUID ist falsch. Ist der Schrank online und die UUID richtig, ist es ein Fehlschlag |
| `FAIL apply_config: <Fehlercode>` | Die Einheit hat die Konfiguration abgelehnt. Fehlschlag, Code notieren |
| `FAIL apply_config: the applied hash differs...` | Die Einheit hat etwas anderes gespeichert. Fehlschlag |
| `FAIL could not connect to the broker` | Anmeldung oder URL sind falsch. Beheben und erneut ausführen |

#### HW-C4: Aus dem Admin-Panel öffnen

Das gibt das Schloss frei, also am Schrank bleiben.

1. `diagnostics` in der Konsole ausführen und `unlock_transmissions` notieren.
2. Den Schrank bearbeiten und **Öffnen** für Fach 1 wählen.
3. `diagnostics` erneut ausführen.

**Bestanden:** Das Schloss gibt einmal frei, und der Zähler steigt um 1.

#### HW-C5: Doppelte Kommandos über den Broker

Das gibt das Schloss frei, also am Schrank bleiben.

1. `diagnostics` ausführen und `unlock_transmissions` notieren.
2. Die [Broker-Prüfung](#gruppe-c-netzwerk-mqtt-und-enrollment) mit `--actuate --compartment 1` ausführen.
3. `diagnostics` erneut ausführen.

**Bestanden:** Die Ausgabe enthält `the duplicates produced 1 response(s)` und `PASSED`, das Schloss bewegt sich einmal, und der Zähler steigt um 1.

#### HW-C6: Netzausfälle überstehen

Das trennt erst den Access Point, dann den Broker. Die Einheit muss sich selbst erholen und eine Antwort nachliefern, die sie nicht senden konnte. Den ESP32 dabei nicht neu starten oder ausstecken, weil HW-C9 seit dem Boot gezählte Werte liest.

Für eine offene Antwort dem Panel den Strom nehmen: Eine Freigabe braucht dann einige Sekunden bis zum Fehlschlag, statt fast sofort fertig zu sein, und das Schloss kann sich nicht bewegen.

1. Prüfen, dass der Schrank online ist.
2. Den Strom des Panels ausschalten, nicht den des ESP32.
3. **Öffnen** für Fach 1 im Admin-Panel wählen.
4. Etwa 2 Sekunden warten, dann dem Access Point den Strom nehmen. Das Warten gibt dem Backend Zeit, das Kommando zu senden. Zu früh getrennt, erhält die Einheit es nie, zu spät getrennt, ist die Antwort schon zugestellt.
5. 1 Minute warten, dann den Access Point wieder einschalten und den Panelstrom wiederherstellen.
6. Warten, bis der Schrank online ist. WLAN-Wiederholungen werden bis auf 30 Sekunden gestreckt, also bis zu etwa 2 Minuten einplanen. Prüfen, dass der PC seine Adresse behalten hat, weil die Einheit sie als Broker-URL gespeichert hat.
7. Die Antwort suchen und ihre Zeit notieren:

   ```bash
   grep 'MQTT response message received' storage/logs/laravel.log | tail -3
   ```

8. Den Broker für 1 Minute stoppen und wieder starten.

   ```bash
   docker compose stop mqtt
   ```

   ```bash
   docker compose start mqtt
   ```

9. Warten, bis der Schrank online ist. Die Einheit verbindet sich innerhalb von Sekunden, der Listener des Backends kann aber etwa 40 Sekunden länger brauchen.
10. [Prüfen, dass die Einheit nicht neu gestartet hat](#manuelle-prüfungen-vorbereiten).

**Bestanden:** Der Schrank hat sich beide Male ohne Hilfe erholt, die Laufzeit stieg weiter, und die Antwort steht im Log, nachdem der Access Point zurück war. Ein Fehlschlagsergebnis ist zu erwarten, weil das Panel keinen Strom hatte: Diese Prüfung betrifft die Zustellung. Das Admin-Panel zeigt das Öffnen womöglich schon als abgelaufen.

Steht die Antwort im Log, bevor der Strom getrennt wurde, war die Trennung zu spät. Dann die Schritte 2 bis 7 wiederholen. Das ist kein Fehlschlag.

Ein fehlgeschlagenes Öffnen nicht mit `queue:retry` wiederholen. Das würde das alte Kommando erneut senden und könnte das Schloss später freigeben, als jemand erwartet.

#### HW-C7: Ungültiges Token ablehnen

Ein falsches Token muss genau eine Ablehnung und keine Wiederholung auslösen, und ein echtes Token muss danach weiterhin registrieren.

1. Ist die Einheit registriert, `reset-network` in der Konsole ausführen.
2. Ein Token im richtigen Format erzeugen, das das Backend nicht kennt:

   ```bash
   python -c "import secrets; print(secrets.token_hex(32))"
   ```

3. Damit in Betrieb nehmen, mit dem normalen WLAN und Broker. Beim lokalen Backend sind Bootstrap-Benutzer und -Passwort `MQTT_PROVISIONING_USERNAME` und `MQTT_PROVISIONING_PASSWORD` aus `locker-backend/.env`.

   ```bash
   python scripts/commission.py --usb COM3
   ```

4. Nach etwa einer Minute nach einer Ablehnung suchen:

   ```bash
   grep -E 'No LockerBank found|Publishing invalid token' storage/logs/laravel.log | tail -4
   ```

5. 2 weitere Minuten warten und denselben Befehl ausführen. Es sollten keine neuen Zeilen erscheinen.
6. `status` in der Konsole ausführen. Es sollte `enrolled=0 pending=0 configured=1` zeigen.
7. Den Schrank im Admin-Panel bearbeiten, **Provisionierung neu starten** wählen und das Token kopieren. Es wird einmal angezeigt und gilt 24 Stunden.
8. `commission.py` mit diesem Token erneut ausführen.

**Bestanden:** eine Ablehnung, keine Wiederholung, `pending=0`, und der Schrank geht mit dem echten Token online.

**Fehlgeschlagen:** weitere Ablehnungen aus demselben Versuch, `pending=1` oder ein echtes Token, das nicht registriert. `pending=1` bedeutet, dass die Einheit die Ablehnung nie verarbeitet hat.

#### HW-C8: Schrank offline anzeigen

1. Die Zeit notieren und den ESP32 ausstecken.
2. **Heartbeat-Timeout (Sekunden)** des Schranks abwarten, standardmäßig 30, plus bis zu einer Minute für die geplante Prüfung.
3. Wieder einstecken.

**Bestanden:** **Status** zeigt nach der Wartezeit Offline und etwa eine Minute nach dem Einstecken wieder Online. Das Backend wertet die Last-Will-Nachricht des Brokers noch nicht aus, den Status ändert also das Heartbeat-Timeout.

#### HW-C9: Speicher nach den Ausfällen festhalten

Direkt nach HW-C6 ausführen, ohne Neustart dazwischen.

1. `diagnostics` in der Konsole ausführen.

**Bestanden:** Die Antwort zeigt `min_free_heap` und `owner_stack_free`. Beide Zahlen in die Notiz schreiben, weil HW-H9 damit vergleicht.

### Gruppe D Schritt für Schritt

Die App-Prüfungen brauchen ein Konto mit Zugriff auf das Fach.

#### HW-D1: Tür von Hand verfolgen

1. Die Fächer des Schranks im Admin-Panel und das Fach in der Mobile App öffnen.
2. Die Tür von Hand öffnen und wieder schließen.

**Bestanden:** Panel und App folgen jeweils innerhalb von zwei Sekunden.

#### HW-D2: Geöffnete Tür melden

Das gibt das Schloss frei, also am Schrank bleiben.

1. **Öffnen** für Fach 1 im Admin-Panel wählen.
2. Die Tür aufziehen.
3. Nach dem Ereignis suchen:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Bestanden:** Das Log zeigt `compartment_open_detected`, und das **Audit-Protokoll** zeigt **Tür geöffnet**.

#### HW-D3: Klemmende Tür melden

Das gibt das Schloss frei, also die Tür zuhalten.

1. **Öffnen** für Fach 1 im Admin-Panel wählen.
2. Die Tür länger als das **Heartbeat-Intervall** des Schranks geschlossen halten, das ist das Erkennungsfenster.
3. Das `grep` aus HW-D2 ausführen.

**Bestanden:** Das Log zeigt `compartment_open_failed`, und das **Audit-Protokoll** zeigt **Tür hat nicht geöffnet**.

#### HW-D4: Öffnen ohne Befehl melden

1. Kein Kommando senden und die Tür von Hand aufdrücken. Hält das Schloss sie geschlossen, die Falle von Hand lösen.
2. Das `grep` aus HW-D2 ausführen.

**Bestanden:** Das Log zeigt `compartment_uncommanded_open`, und das **Audit-Protokoll** zeigt **Türöffnung ohne Befehl**.

#### HW-D5: Panelstrom verlieren und zurückbekommen

1. Den Strom des Panels ausschalten, nicht den des ESP32.
2. Den nächsten Heartbeat abwarten und den Schrank im Admin-Panel beobachten.
3. Den Panelstrom wieder einschalten und etwa eine Minute warten.

**Bestanden:** Ohne Strom zeigt **Hardware** Nicht erreichbar, und die Türen zeigen Unbekannt. Mit Strom erholt sich beides ohne Neustart.

### Gruppe E Schritt für Schritt

#### HW-E1: Gelöschte Einheit in Betrieb nehmen

Das gibt das Schloss frei, also am Schrank bleiben.

1. [Die Einheit vollständig löschen](/de/dokumentation/esp32/maintenance/#einheit-vollständig-löschen).
2. [Die Firmware flashen](/de/dokumentation/esp32/flashing/#firmware-flashen).
3. Im Admin-Panel **Provisionierung neu starten** beim Schrank wählen und das Token kopieren.
4. [Über USB](/de/dokumentation/esp32/commissioning/#über-usb-release--und-development-builds) in Betrieb nehmen.
5. Auf Online warten, dann **Öffnen** für Fach 1 wählen.

**Bestanden:** Der Schrank geht online, und das Schloss gibt einmal frei.

#### HW-E2: Falsche WLAN-Einstellungen korrigieren

Mit einer registrierten, online befindlichen Einheit beginnen. Eine registrierte Einheit nimmt nur neue WLAN-Einstellungen an, also braucht es kein Token.

1. `commission.py --usb COM3` mit einem falschen WLAN-Passwort von mindestens 8 Zeichen ausführen.
2. Warten, bis der Schrank offline ist.
3. `commission.py --usb COM3` erneut mit dem richtigen Passwort ausführen. `reset-network` nicht ausführen.
4. Auf Online warten, dann `status` in der Konsole ausführen.

**Bestanden:** Der Schrank kommt als derselbe Schrank zurück, mit `enrolled=1` und ohne neues Token.

#### HW-E5: Einheit zurücksetzen und neu registrieren

1. Den aktuellen MQTT-Benutzer notieren, mit `DB_USERNAME` und `DB_DATABASE` aus `.env`:

   ```bash
   docker compose exec db psql -U sail -d locker -Atc "select username,enabled,revoked_at from mqtt_users where locker_bank_id='<uuid>'"
   ```

2. `reset-network` in der Konsole ausführen.
3. Im Admin-Panel **Provisionierung neu starten** wählen und das Token kopieren.
4. Die Abfrage erneut ausführen. Der alte Benutzer sollte jetzt deaktiviert sein oder eine `revoked_at`-Zeit haben.
5. Mit dem neuen Token in Betrieb nehmen und auf Online warten.
6. Die Abfrage erneut ausführen.

**Bestanden:** Der alte Benutzer ist widerrufen, ein neuer ist aktiv, und der Schrank ist online. Die Einheit zeigt ihr Passwort nie, deshalb liest diese Prüfung den Widerruf aus der Datenbank, statt eine Anmeldung zu versuchen.

### Gruppe F Schritt für Schritt

Jede Prüfung beginnt mit einer Einheit mit [Setup-Secrets](/de/dokumentation/esp32/flashing/#setup-secrets-schreiben), sofern nichts anderes steht. Zum Zurücksetzen `reset-network` ausführen oder den Taster 10 Sekunden halten. Ein Reset entfernt die Setup-Secrets nicht. Das Setup-Netzwerk öffnet sich nach dem Einschalten für 10 Minuten und nur auf einer Einheit ohne Einstellungen.

#### HW-F1: Über das Setup-Netzwerk in Betrieb nehmen

Das gibt das Schloss frei, also am Schrank bleiben.

1. Die Einheit löschen, ihre Secrets schreiben und sie flashen.
2. Im Admin-Panel **Provisionierung neu starten** wählen und das Token kopieren.
3. Jede USB-Datenverbindung trennen und die Einheit mit Strom versorgen.
4. [Über das Setup-WLAN](/de/dokumentation/esp32/commissioning/#über-das-setup-wlan-empfohlen) in Betrieb nehmen.
5. Auf Online warten, dann **Öffnen** für Fach 1 wählen.

**Bestanden:** Der Schrank geht online, und das Schloss gibt einmal frei. Das Werkzeug meldet nur „saved“, der Erfolg steht also im Panel.

#### HW-F2: Falsches WLAN-Passwort überstehen

1. `commission.py --softap` mit einem falschen WLAN-Passwort und einem unbenutzten Token ausführen.
2. 2 Minuten warten. Der Schrank sollte offline bleiben, und die Konsole sollte wiederholte WLAN-Versuche zeigen.
3. Den Taster 10 Sekunden halten und loslassen. Die Einheit löscht ihre Einstellungen und startet neu.
4. `commission.py --softap` erneut mit dem richtigen Passwort und demselben Token ausführen, innerhalb von 24 Stunden nach dessen Ausstellung.

**Bestanden:** Die Einheit versucht es weiter, der Taster setzt sie zurück, und dasselbe Token registriert sie.

#### HW-F3: Access Point bei registrierter Einheit geschlossen halten

1. Die WLANs in Reichweite auflisten:

   ```bash
   nmcli device wifi list
   ```

2. Die Adresse der Einheit im Router nachsehen und den Setup-Port prüfen:

   ```bash
   nc -zv -w 3 <Adresse der Einheit> 80
   ```

**Bestanden:** Kein `OpenLocker-`-Netz dieser Einheit erscheint, und die Verbindung wird abgelehnt oder läuft in ein Timeout.

#### HW-F4: Taster-Zeiten

Der Taster verbindet standardmäßig GPIO 4 mit GND.

1. Bei einer registrierten Einheit den Taster etwa 5 Sekunden halten und loslassen. `status` sollte weiter `enrolled=1` zeigen.
2. Den Taster 10 Sekunden halten und loslassen. Die Einheit sollte mit `enrolled=0 configured=0` neu starten.
3. Sie erneut in Betrieb nehmen.
4. Sie ausstecken, den Taster halten, einstecken und mehr als 10 Sekunden weiter halten, bevor er losgelassen wird. Sie sollte registriert bleiben.
5. Sie ausstecken, GPIO 4 per Jumper mit GND verbinden, einstecken und mehr als 10 Sekunden warten. Sie sollte registriert bleiben.
6. Den Jumper entfernen.

**Bestanden:** Nur Schritt 2 setzt die Einheit zurück.

#### HW-F5: Setup-Fenster schützen

Jeden Teil damit beginnen, die Einheit zurückzusetzen und einzuschalten.

1. Mit dem Laptop dem Setup-Netzwerk beitreten, dann mit einem Telefon. Das Telefon sollte abgelehnt werden.
2. Erneut einschalten und 10 Minuten warten. Das Setup-Netzwerk sollte verschwinden.
3. Erneut einschalten und `commission.py --softap` mit einem falschen Setup-Passwort ausführen. Es sollte `The setup password was not accepted` melden.
4. Schritt 3 wiederholen, bis 5 Fehlschläge erreicht sind. Das Setup-Netzwerk sollte sich schließen.

**Bestanden:** Alle vier Ergebnisse stimmen.

#### HW-F6: Bench-Befehle im Production-Build ablehnen

Das braucht einen eigenen Build. Danach das Test-Image erneut flashen, weil `--image` das Image auf der Einheit sein muss.

1. Ein [Production-Image](/de/dokumentation/esp32/flashing/#build-wählen) bauen und flashen.
2. Jeden dieser Befehle in der Konsole senden: `configure {}`, `unlock 1 0`, `command {}`, `reset-network`.

**Bestanden:** Jeder antwortet `error invalid command`, und das Schloss bewegt sich nicht.

#### HW-F7: Secrets aus dem Setup-Verkehr heraushalten

Ein Wegwerf-WLAN-Passwort und ein Wegwerf-Token verwenden.

1. Beide Secrets zeilenweise in eine private Datei schreiben:

   ```bash
   install -m 600 /dev/null secrets.txt
   ```

2. Einen Mitschnitt auf der WLAN-Schnittstelle starten:

   ```bash
   sudo tcpdump -i <WLAN-Schnittstelle> -w setup.pcap
   ```

3. `commission.py --softap` bis zum Ende ausführen, dann den Mitschnitt mit `Strg+C` beenden.
4. Den Mitschnitt nach den Secrets durchsuchen. Die Anzahl sollte 0 sein:

   ```bash
   strings -n 8 setup.pcap | grep -c -F -f secrets.txt
   ```

5. `secrets.txt` und `setup.pcap` löschen.

**Bestanden:** Die Anzahl ist 0, und der Mitschnitt enthält Verkehr zu `192.168.4.1`, einschließlich `ol-config`.

#### HW-F8: Abgelaufenes Token ablehnen

Tokens gelten 24 Stunden.

1. Mit **Provisionierung neu starten** ein Token ausstellen und mehr als 24 Stunden aufbewahren. Nur auf einer Prüfaufbau-Datenbank lässt es sich stattdessen altern:

   ```bash
   docker compose exec db psql -U sail -d locker -c "update locker_banks set provisioning_token_issued_at = now() - interval '25 hours' where id='<uuid>'"
   ```

2. Damit in Betrieb nehmen.
3. Nach der Ablehnung suchen:

   ```bash
   grep 'No LockerBank found' storage/logs/laravel.log | tail -2
   ```

4. Ein frisches Token ausstellen und damit in Betrieb nehmen.

**Bestanden:** Das alte Token wird abgelehnt, das frische registriert die Einheit.

### Gruppe H Schritt für Schritt

**Das Backend muss über HTTPS mit einem Zertifikat einer öffentlichen CA erreichbar sein.** Das Backend sendet keinen Link, der nicht mit `https://` beginnt, und die Firmware prüft das Zertifikat gegen ihr eingebautes CA-Bundle. Ein lokales `http://`-Backend kann diese Gruppe nicht ausführen.

Jedes Release braucht eine neue Version, und die Einheit lehnt eine Version ab, von der sie zurückgerollt ist. So entsteht eines, mit demselben Schlüssel wie das Image auf der Einheit:

1. Mit der neuen Version bauen:

   ```bash
   LC_FIRMWARE_VERSION=0.2.0 idf.py reconfigure build
   ```

2. [Signieren](/de/dokumentation/esp32/flashing/#image-signieren).
3. `build/locker-firmware-signed.bin` unter **Firmware-Releases** mit derselben Version hochladen.

Ein Update startet mit **Firmware aktualisieren** beim Schrank. Das Backend prüft nur das erste Byte und die Größe des Images, es nimmt die fehlerhaften Images unten also absichtlich an. Die Konsole zeigt `downloading firmware` beim Start eines Downloads und `verified` bei Erfolg.

Das Backend lehnt ein neues Update ab, solange eines läuft, und beendet ein Update ohne Rückmeldung nach 30 Minuten. Diese Wartezeit einplanen, wenn eine Prüfung Strom oder Netz trennt.

#### HW-H1: Leeres Board mit dem signierten Image flashen

`unlock` wird abgelehnt, sobald die Einheit registriert ist, deshalb HW-A1 und HW-A6 vor der Inbetriebnahme ausführen.

1. [Die Einheit löschen](/de/dokumentation/esp32/maintenance/#einheit-vollständig-löschen) und [das signierte Image flashen](/de/dokumentation/esp32/flashing/#firmware-flashen).
2. Die Bench-Prüfungen ausführen:

   ```bash
   python scripts/hwtest.py run --port COM3 --image build/locker-firmware-signed.bin --only A1,A6
   ```

3. Die Einheit in Betrieb nehmen.
4. HW-C4, HW-D2 und HW-D4 erneut ausführen.

**Bestanden:** Alle bestehen, und der Schrank zeigt die Version des signierten Images.

#### HW-H2: Untätige Einheit aktualisieren

1. Ein neues Release erstellen und hochladen.
2. Das Update starten und den Neustart der Einheit abwarten.

**Bestanden:** **Firmware** zeigt die neue Version, und **Firmware-Update** zeigt nach der Probephase Abgeschlossen.

#### HW-H3: Öffnen während eines Updates und einer Probephase

Das gibt das Schloss frei, also am Schrank bleiben. Der Weg zum Rollback in Teil 2 ist ein Vorschlag, weil die Prüfung ihn nicht festlegt.

1. Ein Update starten und auf `downloading firmware` warten.
2. **Öffnen** für Fach 1 wählen. Das Schloss sollte einmal freigeben, und die Antwort sollte im Log stehen.
3. Das Update abschließen lassen.
4. Ein weiteres Update starten. Zeigt die Konsole den Neustart, den Panelstrom ausschalten, damit das neue Image den Bus nicht erreicht.
5. Ist der Schrank wieder online, innerhalb der 180-sekündigen Probephase **Öffnen** für Fach 1 wählen.
6. Den Rollback abwarten, dann den Panelstrom wiederherstellen.

**Bestanden:** Jedes Öffnen erhält genau eine Antwort, und keines geht verloren. Das zweite Ergebnis ist ein Fehlschlag, weil das Panel keinen Strom hatte.

#### HW-H4: Fehlerhafte Signaturen ablehnen

1. Ein neues Release bauen und signieren, dann ein Byte in seinem Signatursektor beschädigen:

   ```bash
   python -c "import pathlib as p; f=p.Path('build/locker-firmware-signed.bin'); d=bytearray(f.read_bytes()); d[-3196]^=1; p.Path('build/bad.bin').write_bytes(d)"
   ```

2. `build/bad.bin` als diese Version hochladen und das Update starten.
3. Ein Release mit der nächsten Version bauen und mit einem anderen Schlüssel signieren.
4. Hochladen und das Update starten.

**Bestanden:** Die Konsole zeigt beide Male `firmware download failed`, **Firmware** behält die alte Version, und **Firmware-Update** zeigt Fehlgeschlagen.

#### HW-H5: Abgeschnittenes Image und WLAN-Ausfall überstehen

1. Ein neues Release bauen und signieren, dann kürzen:

   ```bash
   head -c 600000 build/locker-firmware-signed.bin > build/short.bin
   ```

2. `build/short.bin` als diese Version hochladen und das Update starten.
3. Warten, bis das Backend den Fehler zeigt. Das kann bis zu 30 Minuten dauern.
4. Ein Release mit der nächsten Version hochladen und das Update starten.
5. Nach `downloading firmware` den Access Point für 1 Minute ausschalten.
6. Wieder einschalten und bis zu 30 Minuten warten, bis das Backend den Fehler zeigt.

**Bestanden:** Die alte Version läuft beide Male weiter, und das Backend zeigt beide Updates als fehlgeschlagen.

#### HW-H6: Zurückrollen, wenn der Broker nicht erreichbar ist

1. Ein neues Release hochladen und das Update starten.
2. Sobald die Konsole `verified` zeigt, den Broker mit `docker compose stop mqtt` stoppen.
3. Nach dem Neustart der Einheit mehr als 180 Sekunden warten. Sie sollte erneut neu starten, in die alte Version.
4. Den Broker mit `docker compose start mqtt` starten.

**Bestanden:** Die Einheit ist selbst zurückgekehrt, **Firmware** zeigt die alte Version, und **Firmware-Update** zeigt Fehlgeschlagen mit dem Grund Rollback.

#### HW-H7: Strom während des Image-Schreibens trennen

Das Backend lehnt eine Wiederholung ab, bis das vorige Update abgelaufen ist, zehn Runden dauern also etwa 5 Stunden.

1. Ein Update starten und auf `downloading firmware` warten.
2. Den ESP32 während des Downloads ausstecken.
3. Wieder einstecken und prüfen, dass er bootet und online geht.
4. Warten, bis **Firmware-Update** Fehlgeschlagen zeigt.
5. Wiederholen, bis das zehnmal erfolgt ist.

**Bestanden:** Er bootet jedes Mal ein gültiges Image und geht online.

#### HW-H8: Identität, Zuordnung und Journal behalten

Nach HW-H2 und erneut nach HW-H6 ausführen.

1. Vor dem Update die Abfrage aus HW-E5 ausführen und den MQTT-Benutzer notieren.
2. Die `transaction_id` eines abgeschlossenen Öffnens im Log suchen.
3. Nach dem Update `status` ausführen. Es sollte `enrolled=1` zeigen.
4. Die Abfrage aus HW-E5 ausführen. Der MQTT-Benutzer sollte derselbe sein. Dann `diagnostics` ausführen und `unlock_transmissions` notieren.
5. Dieses Öffnen mit einer neuen `message_id` erneut senden. Die Einheit sollte ihr gespeichertes Ergebnis antworten und nicht freigeben.

   ```bash
   docker compose exec mqtt mosquitto_pub -h localhost -u laravel_backend -P "$BROKER_PASSWORD" -q 1 -t locker/<uuid>/command -m '{"message_id":"<neue uuid>","transaction_id":"<transaction id>","action":"open_compartment","timestamp":"<jetzt, ISO 8601>","data":{"compartment_number":1}}'
   ```

6. `diagnostics` ausführen. `unlock_transmissions` zählt seit dem Boot, also mit dem Wert direkt vor Schritt 5 vergleichen.
7. **Öffnen** für Fach 1 wählen. Das gibt das Schloss frei, also am Schrank bleiben.

**Bestanden:** Der Benutzer ist derselbe, eine gespeicherte Antwort kommt zurück, der Zähler stieg durch die Wiederholung nicht, und das zugeordnete Fach öffnet weiterhin.

#### HW-H9: Speicher während eines Downloads prüfen

`min_free_heap` ist der niedrigste Wert seit dem Boot, ein Wert nach einem Download schließt diesen also ein.

1. Die Einheit neu starten und auf Online warten.
2. Ein Update starten.
3. `diagnostics` ausführen, wenn die Konsole `verified` zeigt, bevor die Einheit neu startet.

**Bestanden:** `owner_stack_free` ist mindestens der in HW-C9 festgehaltene Wert. Beide `diagnostics`-Werte in die Notiz schreiben.

#### HW-H10: App aus dem nächsten ESP-IDF-Patch booten

Die Einheit behält den per USB geflashten Bootloader.

1. Dieselbe Firmware mit dem nächsten ESP-IDF-Patch-Release und einer neuen Version bauen.
2. Signieren, hochladen und das Update starten.

**Bestanden:** Die Einheit bootet das neue Image, und **Firmware** zeigt seine Version.

Gibt es noch kein neueres Patch-Release, **Not run** wählen und das im PR vermerken. `hwtest.py check` schlägt weiter fehl, bis diese Prüfung besteht oder zurückgestellt ist.

#### HW-H11: Journal nach einem Rollback lesen

1. HW-H3 Teil 2 ausführen, damit ein Image in der Probephase ein Öffnen behandelt und dann zurückrollt.
2. Die `transaction_id` dieses Öffnens im Log suchen.
3. Es wie in HW-H8 erneut senden.
4. Den Panelstrom wiederherstellen und **Öffnen** für Fach 1 wählen.

**Bestanden:** Das ältere Image antwortet mit dem gespeicherten Ergebnis und gibt nicht frei, dann gibt ein neues Öffnen das Schloss einmal frei.

## Warum die zurückgestellten Prüfungen zurückgestellt sind

| Prüfung | Grund |
| --- | --- |
| HW-A9 | 200 Betätigungen. Der Prüfaufbau maß etwa 265 ms gegenüber 1500 ms Frist und 3000 ms Settle-Zeit, und HW-A7 prüft Abfragen erneut |
| HW-B5 | Braucht Fehlerinjektion. Von Host-Tests abgedeckt |
| HW-B6 | Braucht eine eigene Einheit und 600 Kommandos. Kompaktierung und Retention deckt `tests/host/test_journal_retention.c` ab |
| HW-C2 | Braucht einen TLS-Broker mit fehlerhaften Zertifikaten, der Bench-Broker hat kein TLS. Noch keine Prüfung deckt eine verifizierte `mqtts://`-Verbindung ab |
| HW-D6 | Ein Randfall |
| HW-E3 | Braucht ein zweites Netzwerk |
| HW-E4 | Zehn manuelle Trennungen |
| HW-G1 | Ein 72-Stunden-Lauf mit 432 Betätigungen |
| HW-G2 | Testet den Provisionierungs-Reset des Pi Clients, nicht diese Firmware |
