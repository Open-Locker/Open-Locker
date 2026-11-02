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

`--image` ist das geflashte `.bin`, und es muss genau dieses sein: Sein SHA-256 steht im Nachweis. Das Werkzeug steuert die USB-Konsole und fragt nach den physischen Schritten. Vor jeder Prüfung, die das Schloss freigibt, `go` oder `skip` eingeben.

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

**auto** bedeutet, das Werkzeug steuert die Prüfung und fragt nur, was es nicht sehen kann. **manuell** bedeutet, die Prüfung läuft anderswo, und das Ergebnis wird gemeldet. **zurückgestellt** bedeutet, sie wird mit Grund als nicht ausgeführt festgehalten und blockiert den Nachweis nicht.

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
