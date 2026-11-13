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
| HW-B3 | Neustart: Die Zuordnung gilt weiter, und das Öffnen aus B1 gibt sein gespeichertes Ergebnis ohne Freigabe wieder | auto |
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
| HW-C9 | Direkt nach HW-C6, ohne Neustart dazwischen, zeigt `diagnostics` den geringsten freien Heap und die Stack-Reserve des Owner-Tasks. Beides festhalten | manuell |
| HW-C7 | Ungültiges Token: abgelehnt ohne Wiederholung. Nach einem Provisionierungs-Reset und einem frischen Token registriert sie sich | manuell |
| HW-C8 | Strom trennen: Das Backend zeigt den Schrank offline | manuell |

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
| HW-E1 | **Löst aus.** Eine Einheit ohne Einstellungen mit `commission.py` bis zum Öffnen in Betrieb nehmen | manuell |
| HW-E2 | Falsche WLAN-Zugangsdaten, über USB ohne Reset korrigiert: Die Identität bleibt | manuell |
| HW-E3 | Eine registrierte Einheit in ein anderes Netz umziehen: Identität, Zuordnung und Journal bleiben | zurückgestellt |
| HW-E4 | Zehnmal USB während des Speicherns trennen: alte oder neue Einstellungen, nie eine Mischung | zurückgestellt |
| HW-E5 | Reset: Alte Zugangsdaten schlagen fehl, und ein frisches Token registriert die Einheit | manuell |

### Gruppe F: Inbetriebnahme über das Setup-Netzwerk

Benötigt eine Einheit mit Setup-Secrets, einen Laptop mit `commission.py --softap` aus demselben Build und für HW-F6 einen Production-Build.

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-F1 | **Löst aus.** Eine Einheit ohne Einstellungen über das Setup-Netzwerk bis zum Öffnen aus dem Admin-Panel in Betrieb nehmen | manuell |
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

:::note[Geplant]
Over-the-Air-Updates sind noch nicht umgesetzt. Sie kommen mit [#336](https://github.com/Open-Locker/Open-Locker/pull/336), sobald das
Konzept für die Image-Signierung steht. Dieser Abschnitt beschreibt den Plan.
:::

Benötigt eine per USB mit einem signierten Image geflashte Einheit, ein Backend mit Firmware-Releases und ein zweites signiertes Release. Läuft, nachdem die Gruppen A bis E und G bestanden sind.

| ID | Prüfung | Art |
| --- | --- | --- |
| HW-H1 | Der gesamte Lauf begann auf einem leeren, mit dem signierten Image geflashten Board: das bestätigen, ohne erneuten Lauf | manuell |
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

**Eine Aktion kann mehrere Prüfungen beantworten.** `hwtest.py` listet sie dann zusammen auf, fragt einmal und speichert die Antwort für alle. Ist nur eine davon fehlgeschlagen, **Fail** wählen und sie in der Notiz nennen. Es fragt in dieser Reihenfolge, die von den Tabellen oben abweicht:

| Schritt | Prüfungen |
| --- | --- |
| Bench-Modus | Gruppen A und B |
| In Betrieb nehmen und öffnen | HW-C1, HW-C4, HW-D2, HW-E1 |
| Broker-Kommandos | HW-C3, HW-C5 |
| Ausfälle | HW-C6, dann HW-C9, dann HW-D5 |
| Tokens | HW-C7, HW-E5 |
| Strom | HW-C8 |
| Tür | HW-D1, HW-D4, dann HW-D3 |
| WLAN über USB | HW-E2 |
| Setup-Netzwerk | HW-F8, HW-F1, HW-F7, dann HW-F4, HW-F2, HW-F3, dann HW-F5, dann HW-F6 |
| Updates | HW-H1, dann HW-H2, HW-H9, dann HW-H6, HW-H8, dann HW-H3, HW-H11, dann HW-H4, HW-H5, HW-H7, HW-H10 |

### Gruppe C Schritt für Schritt

#### HW-C1, HW-C4, HW-D2 und HW-E1: Einheit in Betrieb nehmen und öffnen

`hwtest.py` fragt einmal und speichert die Antwort für alle vier. Das gibt das Schloss frei, also am Schrank bleiben.

1. Hat die Einheit Einstellungen, `reset-network` in der Konsole ausführen. Das Journal bleibt erhalten, was in Ordnung ist: Die Prüfung braucht eine Einheit ohne Einstellungen, kein vollständiges Löschen.
2. Im Admin-Panel **Provisionierung neu starten** beim Schrank wählen und das Token kopieren.
3. Die Einheit [über USB](/de/dokumentation/esp32/commissioning/#über-usb-release--und-development-builds) in Betrieb nehmen.
4. **Schließfachschränke** öffnen und warten, bis **Status** Online zeigt. Das dauert etwa eine Minute.
5. `diagnostics` in der Konsole ausführen und `unlock_transmissions` notieren.
6. Den Schrank bearbeiten und **Öffnen** für Fach 1 wählen.
7. Die Tür aufziehen.
8. `diagnostics` erneut ausführen und nach dem Türereignis suchen:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Bestanden:** Der Schrank geht online, das Schloss gibt einmal frei, der Zähler steigt um 1, das Log zeigt `compartment_open_detected`, und das **Audit-Protokoll** zeigt **Tür geöffnet**.

#### HW-C3 und HW-C5: Kommandos über den Broker prüfen

Ein Lauf der Broker-Prüfung beantwortet beide, und `hwtest.py` fragt einmal. Er sendet der Einheit eine Konfiguration und prüft, ob sie dieselbe gespeichert hat, und sendet dann ein Öffnen und zwei Duplikate. Zuordnung und Heartbeat-Intervall der Einheit werden ersetzt, `--compartments` muss also zur Verdrahtung passen. Das gibt das Schloss frei, also am Schrank bleiben.

1. `diagnostics` in der Konsole ausführen und `unlock_transmissions` notieren.
2. Die [Broker-Prüfung](#gruppe-c-netzwerk-mqtt-und-enrollment) mit `--actuate --compartment 1` ausführen. Beim lokalen Backend sind `BROKER_USER` und `BROKER_PASSWORD` die Werte `MQTT_USERNAME` und `MQTT_PASSWORD` aus `locker-backend/.env`, und die URL ist `mqtt://<Adresse des eigenen PCs>:1883`.
3. `diagnostics` erneut ausführen.

**Bestanden:** Die Ausgabe zeigt `PASS apply_config acknowledged with the matching hash`, `the duplicates produced 1 response(s)` und `PASSED`, das Schloss bewegt sich einmal, und der Zähler steigt um 1.

| Ausgabe | Bedeutung |
| --- | --- |
| `FAIL apply_config: no response` | Die Einheit ist offline, oder die UUID ist falsch. Ist der Schrank online und die UUID richtig, ist es ein Fehlschlag |
| `FAIL apply_config: <Fehlercode>` | Die Einheit hat die Konfiguration abgelehnt. Fehlschlag, Code notieren |
| `FAIL apply_config: the applied hash differs...` | Die Einheit hat etwas anderes gespeichert. Fehlschlag |
| `FAIL could not connect to the broker` | Anmeldung oder URL sind falsch. Beheben und erneut ausführen |

#### HW-C6: Netzausfälle überstehen

Das trennt erst den Access Point, dann den Broker. Die Einheit muss sich selbst erholen und eine Antwort nachliefern, die sie nicht senden konnte. Den ESP32 dabei nicht neu starten oder ausstecken, weil HW-C9 seit dem Boot gezählte Werte liest.

Für eine offene Antwort dem Panel den Strom nehmen: Eine Freigabe braucht dann einige Sekunden bis zum Fehlschlag, statt fast sofort fertig zu sein, und das Schloss kann sich nicht bewegen.

1. Prüfen, dass der Schrank online ist.
2. Den Strom des Panels ausschalten, nicht den des ESP32. **Hardware** und die Türen kurz beobachten, weil HW-D5 danach fragt.
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

#### HW-C9: Speicher nach den Ausfällen festhalten

`hwtest.py` führt das direkt nach HW-C6 aus. Wer HW-C9 auswählt, führt auch HW-C6 aus, weil ein Neustart dazwischen die Werte zurücksetzt.

1. `diagnostics` in der Konsole ausführen.

**Bestanden:** Die Antwort zeigt `min_free_heap` und `owner_stack_free`, und `uptime_seconds` ist seit HW-C6 weiter gestiegen. Beide Zahlen in die Notiz schreiben, weil HW-H9 damit vergleicht.

#### HW-C7 und HW-E5: Ungültiges Token ablehnen und neu registrieren

`hwtest.py` fragt einmal und speichert die Antwort für beide. Ein falsches Token muss genau eine Ablehnung und keine Wiederholung auslösen. Nach einem Reset muss der alte MQTT-Benutzer widerrufen sein, und ein echtes Token muss die Einheit registrieren.

1. Den aktuellen MQTT-Benutzer notieren. Die Befehle auf dieser Seite nutzen die Standardwerte für `DB_USERNAME` (`sail`) und `DB_DATABASE` (`locker`). Weicht die `.env` ab, diese ersetzen:

   ```bash
   docker compose exec db psql -U sail -d locker -Atc "select username,enabled,revoked_at from mqtt_users where locker_bank_id='<uuid>'"
   ```

2. `reset-network` in der Konsole ausführen.
3. Ein Token im richtigen Format erzeugen, das das Backend nicht kennt:

   ```bash
   python -c "import secrets; print(secrets.token_hex(32))"
   ```

4. Damit in Betrieb nehmen, mit dem normalen WLAN und Broker. Beim lokalen Backend sind Bootstrap-Benutzer und -Passwort `MQTT_PROVISIONING_USERNAME` und `MQTT_PROVISIONING_PASSWORD` aus `locker-backend/.env`.

   ```bash
   python scripts/commission.py --usb COM3
   ```

5. Nach etwa einer Minute nach einer Ablehnung suchen:

   ```bash
   grep -E 'No LockerBank found|Publishing invalid token' storage/logs/laravel.log | tail -4
   ```

6. 2 weitere Minuten warten und denselben Befehl ausführen. Es sollten keine neuen Zeilen erscheinen.
7. `status` in der Konsole ausführen. Es sollte `enrolled=0 pending=0 configured=1` zeigen.
8. Den Schrank im Admin-Panel bearbeiten, **Provisionierung neu starten** wählen und das Token kopieren. Es wird einmal angezeigt und gilt 24 Stunden.
9. Die Abfrage aus Schritt 1 ausführen. Der alte Benutzer sollte jetzt deaktiviert sein und eine `revoked_at`-Zeit haben.
10. `commission.py` mit diesem Token erneut ausführen und auf Online warten.
11. Die Abfrage erneut ausführen.

**Bestanden:** eine Ablehnung, keine Wiederholung, `pending=0`, der alte Benutzer ist widerrufen, ein neuer ist aktiv, und der Schrank geht online. Die Einheit zeigt ihr Passwort nie, deshalb liest diese Prüfung den Widerruf aus der Datenbank, statt eine Anmeldung zu versuchen.

**Fehlgeschlagen:** weitere Ablehnungen aus demselben Versuch, `pending=1` oder ein echtes Token, das nicht registriert. `pending=1` bedeutet, dass die Einheit die Ablehnung nie verarbeitet hat.

#### HW-C8: Schrank offline anzeigen

1. Die Zeit notieren und den ESP32 ausstecken.
2. **Heartbeat-Timeout (Sekunden)** des Schranks abwarten, standardmäßig 30, plus bis zu einer Minute für die geplante Prüfung.
3. Wieder einstecken.

**Bestanden:** **Status** zeigt nach der Wartezeit Offline und etwa eine Minute nach dem Einstecken wieder Online. Das Backend wertet die Last-Will-Nachricht des Brokers noch nicht aus, den Status ändert also das Heartbeat-Timeout.

### Gruppe D Schritt für Schritt

Die App-Prüfungen brauchen ein Konto mit Zugriff auf das Fach.

#### HW-D1 und HW-D4: Tür von Hand öffnen

`hwtest.py` fragt einmal und speichert die Antwort für beide.

1. Die Fächer des Schranks im Admin-Panel und das Fach in der Mobile App öffnen.
2. Kein Kommando senden und die Tür von Hand öffnen. Hält das Schloss sie geschlossen, die Falle von Hand lösen.
3. Die Tür schließen.
4. Nach dem Ereignis suchen:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Bestanden:** Panel und App folgen jeweils innerhalb von zwei Sekunden, das Log zeigt `compartment_uncommanded_open`, und das **Audit-Protokoll** zeigt **Türöffnung ohne Befehl**.

#### HW-D3: Klemmende Tür melden

Das gibt das Schloss frei, also die Tür zuhalten.

1. **Öffnen** für Fach 1 im Admin-Panel wählen.
2. Die Tür länger als das **Heartbeat-Intervall** des Schranks geschlossen halten, das ist das Erkennungsfenster.
3. Nach dem Ereignis suchen:

   ```bash
   grep 'Device event received' storage/logs/laravel.log | tail -3
   ```

**Bestanden:** Das Log zeigt `compartment_open_failed`, und das **Audit-Protokoll** zeigt **Tür hat nicht geöffnet**.

#### HW-D5: Panelstrom verlieren und zurückbekommen

`hwtest.py` fragt das direkt nach HW-C9, das Gesehene aus der Panel-Trennung in HW-C6 lässt sich also melden. Wurde es dort nicht beobachtet oder läuft es allein:

1. Den Strom des Panels ausschalten, nicht den des ESP32.
2. Den nächsten Heartbeat abwarten und den Schrank im Admin-Panel beobachten.
3. Den Panelstrom wieder einschalten und etwa eine Minute warten.

**Bestanden:** Ohne Strom zeigt **Hardware** Nicht erreichbar, und die Türen zeigen Unbekannt. Mit Strom erholt sich beides ohne Neustart.

### Gruppe E Schritt für Schritt

#### HW-E2: Falsche WLAN-Einstellungen korrigieren

Mit einer registrierten, online befindlichen Einheit beginnen. Eine registrierte Einheit nimmt nur neue WLAN-Einstellungen an, also braucht es kein Token.

1. `commission.py --usb COM3` mit einem falschen WLAN-Passwort von mindestens 8 Zeichen ausführen.
2. Warten, bis der Schrank offline ist.
3. `commission.py --usb COM3` erneut mit dem richtigen Passwort ausführen. `reset-network` nicht ausführen.
4. Auf Online warten, dann `status` in der Konsole ausführen.

**Bestanden:** Der Schrank kommt als derselbe Schrank zurück, mit `enrolled=1` und ohne neues Token.

### Gruppe F Schritt für Schritt

Jede Prüfung beginnt mit einer Einheit mit [Setup-Secrets](/de/dokumentation/esp32/flashing/#setup-secrets-schreiben), sofern nichts anderes steht. Zum Zurücksetzen `reset-network` ausführen oder den Taster 10 Sekunden halten. Ein Reset entfernt die Setup-Secrets nicht. Das Setup-Netzwerk öffnet sich nach dem Einschalten für 10 Minuten und nur auf einer Einheit ohne Einstellungen.

#### HW-F8, HW-F1 und HW-F7: Abgelaufenes Token ablehnen und über das Setup-Netzwerk in Betrieb nehmen

`hwtest.py` fragt einmal und speichert die Antwort für alle drei, weil eine einzige Inbetriebnahme alle Ergebnisse liefert. Das gibt das Schloss frei, also am Schrank bleiben. Für den Mitschnitt ein Wegwerf-WLAN-Passwort und ein Wegwerf-Token verwenden.

1. Die Einheit zurücksetzen, damit sie keine Einstellungen hat und ihr Setup-Netzwerk öffnet.
2. Mit **Provisionierung neu starten** ein Token ausstellen und mehr als 24 Stunden aufbewahren. Nur auf einer Prüfaufbau-Datenbank lässt es sich stattdessen altern:

   ```bash
   docker compose exec db psql -U sail -d locker -c "update locker_banks set provisioning_token_issued_at = now() - interval '25 hours' where id='<uuid>'"
   ```

3. Damit in Betrieb nehmen und nach der Ablehnung suchen:

   ```bash
   grep 'No LockerBank found' storage/logs/laravel.log | tail -2
   ```

4. Die Einheit erneut zurücksetzen und **Provisionierung neu starten** für ein frisches Token wählen.
5. WLAN-Passwort und frisches Token zeilenweise in eine private Datei schreiben:

   ```bash
   install -m 600 /dev/null secrets.txt
   ```

6. Einen Mitschnitt auf der WLAN-Schnittstelle starten:

   ```bash
   sudo tcpdump -i <WLAN-Schnittstelle> -w setup.pcap
   ```

7. Jede USB-Datenverbindung trennen, die Einheit mit Strom versorgen und [über das Setup-WLAN](/de/dokumentation/esp32/commissioning/#über-das-setup-wlan-empfohlen) in Betrieb nehmen.
8. Den Mitschnitt mit `Strg+C` beenden und nach den Secrets durchsuchen:

   ```bash
   strings -n 8 setup.pcap | grep -c -F -f secrets.txt
   ```

9. `secrets.txt` und `setup.pcap` löschen.
10. Auf Online warten, dann **Öffnen** für Fach 1 wählen.

**Bestanden:** Das alte Token wird abgelehnt, das frische registriert die Einheit, das Schloss gibt einmal frei, die Anzahl ist 0, und der Mitschnitt enthält Verkehr zu `192.168.4.1`, einschließlich `ol-config`. Das Werkzeug meldet nur „saved“, der Erfolg steht also im Panel.

#### HW-F4, HW-F2 und HW-F3: Taster, falsches WLAN-Passwort und geschlossener Access Point

`hwtest.py` fragt einmal und speichert die Antwort für alle drei. Mit einer registrierten Einheit beginnen. Der Taster verbindet standardmäßig GPIO 4 mit GND.

1. Den Taster etwa 5 Sekunden halten und loslassen. `status` sollte weiter `enrolled=1` zeigen.
2. Die Einheit ausstecken, den Taster halten, einstecken und mehr als 10 Sekunden weiter halten, bevor er losgelassen wird. Sie sollte registriert bleiben.
3. Sie ausstecken, GPIO 4 per Jumper mit GND verbinden, einstecken und mehr als 10 Sekunden warten. Sie sollte registriert bleiben.
4. Sie ausstecken, den Jumper entfernen und einstecken.
5. Den Taster 10 Sekunden halten und loslassen. Die Einheit sollte mit `enrolled=0 configured=0` neu starten.
6. Im Admin-Panel **Provisionierung neu starten** wählen und das Token kopieren.
7. `commission.py --softap` mit einem falschen WLAN-Passwort und diesem Token ausführen.
8. 2 Minuten warten. Der Schrank sollte offline bleiben, und die Konsole sollte wiederholte WLAN-Versuche zeigen.
9. Den Taster 10 Sekunden halten und loslassen. Die Einheit löscht ihre Einstellungen und startet neu.
10. `commission.py --softap` erneut mit dem richtigen Passwort und demselben Token ausführen, innerhalb von 24 Stunden nach dessen Ausstellung. Auf Online warten.
11. Die WLANs in Reichweite auflisten:

    ```bash
    nmcli device wifi list
    ```

12. Die Adresse der Einheit im Router nachsehen und den Setup-Port prüfen:

    ```bash
    nc -zv -w 3 <Adresse der Einheit> 80
    ```

**Bestanden:** Nur die Schritte 5 und 9 setzen die Einheit zurück, sie versucht das falsche Passwort weiter, dasselbe Token registriert sie, kein `OpenLocker-`-Netz dieser Einheit erscheint, und die Verbindung wird abgelehnt oder läuft in ein Timeout.

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

#### HW-H1: Bestätigen, dass der Lauf mit einem signierten Image begann

Es gibt keinen erneuten Lauf, weil der Rest des Laufs das auf diesem Image bereits abdeckt.

1. Prüfen, dass dieser Lauf auf einem gelöschten und mit dem signierten Image geflashten Board begann und `--image` `build/locker-firmware-signed.bin` ist.
2. Prüfen, dass **Firmware** beim Schrank die Version dieses Images zeigt.

**Bestanden:** Beides trifft zu. Wurde während des Laufs etwas anderes geflasht als der Production-Build von HW-F6, nach dem das signierte Image wieder geflasht wurde, mit einem leeren Board neu beginnen.

#### HW-H2 und HW-H9: Untätige Einheit aktualisieren und Speicher prüfen

`hwtest.py` fragt einmal und speichert die Antwort für beide. `min_free_heap` ist der niedrigste Wert seit dem Boot, ein Wert nach einem Download schließt diesen also ein.

1. Ein neues Release erstellen und hochladen.
2. Das Update starten.
3. `diagnostics` ausführen, wenn die Konsole `verified` zeigt, bevor die Einheit neu startet.
4. Den Neustart der Einheit abwarten.

**Bestanden:** **Firmware** zeigt die neue Version, **Firmware-Update** zeigt nach der Probephase Abgeschlossen, und `owner_stack_free` ist mindestens der in HW-C9 festgehaltene Wert. Beide `diagnostics`-Werte in die Notiz schreiben.

#### HW-H3 und HW-H11: Öffnen während eines Updates und Journal nach einem Rollback lesen

`hwtest.py` fragt einmal und speichert die Antwort für beide. Das gibt das Schloss frei, also am Schrank bleiben. Der Weg zum Rollback ist ein Vorschlag, weil die Prüfung ihn nicht festlegt.

1. Ein Update starten und auf `downloading firmware` warten.
2. **Öffnen** für Fach 1 wählen. Das Schloss sollte einmal freigeben, und die Antwort sollte im Log stehen.
3. Das Update abschließen lassen.
4. Ein weiteres Update starten. Zeigt die Konsole den Neustart, den Panelstrom ausschalten, damit das neue Image den Bus nicht erreicht.
5. Ist der Schrank wieder online, innerhalb der 180-sekündigen Probephase **Öffnen** für Fach 1 wählen.
6. Den Rollback abwarten und die `transaction_id` dieses Öffnens im Log suchen.
7. [Es erneut senden](#hw-h6-und-hw-h8-zurückrollen-und-identität-zuordnung-und-journal-behalten). Das ältere Image sollte sein gespeichertes Ergebnis antworten und nicht freigeben.
8. Den Panelstrom wiederherstellen und **Öffnen** für Fach 1 wählen.

**Bestanden:** Jedes Öffnen erhält genau eine Antwort, keines geht verloren, die Wiederholung gibt nicht frei, und das neue Öffnen gibt das Schloss einmal frei. Das Ergebnis in der Probephase ist ein Fehlschlag, weil das Panel keinen Strom hatte.

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

#### HW-H6 und HW-H8: Zurückrollen und Identität, Zuordnung und Journal behalten

`hwtest.py` fragt einmal und speichert die Antwort für beide. HW-H8 deckt ein Update und einen Rollback ab, die Schritte 2 bis 6 laufen also zweimal: einmal nach HW-H2, bevor es hier losgeht, und einmal am Ende. Das gibt das Schloss frei, also am Schrank bleiben.

1. Die MQTT-Benutzer-Abfrage aus HW-C7 ausführen und den Benutzer notieren. Die `transaction_id` eines abgeschlossenen Öffnens im Log suchen.
2. `status` ausführen. Es sollte `enrolled=1` zeigen.
3. Die Abfrage erneut ausführen. Der MQTT-Benutzer sollte derselbe sein.
4. `diagnostics` ausführen und `unlock_transmissions` notieren.
5. Dieses Öffnen mit einer neuen `message_id` erneut senden. Die Einheit sollte ihr gespeichertes Ergebnis antworten und nicht freigeben. Zuerst die beiden Werte aus `locker-backend/.env` in dieser Shell setzen:

   ```bash
   export BROKER_USER=<MQTT_USERNAME> BROKER_PASSWORD=<MQTT_PASSWORD>
   docker compose exec mqtt mosquitto_pub -h localhost -u "$BROKER_USER" -P "$BROKER_PASSWORD" -q 1 -t locker/<uuid>/command -m '{"message_id":"<neue uuid>","transaction_id":"<transaction id>","action":"open_compartment","timestamp":"<jetzt, ISO 8601>","data":{"compartment_number":1}}'
   ```

6. `diagnostics` erneut ausführen, dann **Öffnen** für Fach 1 wählen.
7. Ein neues Release hochladen und das Update starten.
8. Sobald die Konsole `verified` zeigt, den Broker mit `docker compose stop mqtt` stoppen.
9. Nach dem Neustart der Einheit mehr als 180 Sekunden warten. Sie sollte erneut neu starten, in die alte Version.
10. Den Broker mit `docker compose start mqtt` starten und auf Online warten.
11. Die Schritte 2 bis 6 erneut ausführen.

**Bestanden:** Die Einheit ist selbst zurückgekehrt, **Firmware** zeigt die alte Version, **Firmware-Update** zeigt Fehlgeschlagen mit dem Grund Rollback. Beide Male ist der Benutzer derselbe, eine gespeicherte Antwort kommt zurück, der Zähler stieg durch die Wiederholung nicht, und das Fach öffnet weiterhin.

#### HW-H7: Strom während des Image-Schreibens trennen

Das Backend lehnt eine Wiederholung ab, bis das vorige Update abgelaufen ist, zehn Runden dauern also etwa 5 Stunden.

1. Ein Update starten und auf `downloading firmware` warten.
2. Den ESP32 während des Downloads ausstecken.
3. Wieder einstecken und prüfen, dass er bootet und online geht.
4. Warten, bis **Firmware-Update** Fehlgeschlagen zeigt.
5. Wiederholen, bis das zehnmal erfolgt ist.

**Bestanden:** Er bootet jedes Mal ein gültiges Image und geht online.

#### HW-H10: App aus dem nächsten ESP-IDF-Patch booten

Die Einheit behält den per USB geflashten Bootloader.

1. Dieselbe Firmware mit dem nächsten ESP-IDF-Patch-Release und einer neuen Version bauen.
2. Signieren, hochladen und das Update starten.

**Bestanden:** Die Einheit bootet das neue Image, und **Firmware** zeigt seine Version.

Gibt es noch kein neueres Patch-Release, **Not run** wählen und das im PR vermerken. `hwtest.py check` schlägt weiter fehl, bis diese Prüfung besteht oder zurückgestellt ist.

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
