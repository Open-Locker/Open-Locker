---
title: Warten und zurücksetzen
description: Eine Einheit zurücksetzen, ihr WLAN ändern, ihre Firmware aktualisieren oder einen Schrank an den Raspberry-Pi-Client zurückgeben.
sidebar:
  order: 9
---

Diese Seite behandelt Änderungen an einer Einheit, die bereits läuft.

## Einheit zurücksetzen

Ein Reset löscht die Identität, die Fachzuordnung und die Netzwerkeinstellungen. Er behält das Kommando-Journal und die
Setup-Secrets vom Etikett. Die Einheit startet dann neu und öffnet wieder ihr Setup-Netzwerk.

1. Prüfen, dass die Einheit mit losgelassenem Taster mindestens 1 Sekunde eingeschaltet ist.
2. Den Taster 10 Sekunden halten und dann loslassen. Der Reset geschieht beim Loslassen. Das funktioniert bei jedem Build.

| Halten | Ergebnis |
| --- | --- |
| Unter 10 s | Nichts |
| 10 s bis unter 30 s | Reset, einmal, beim Loslassen |
| 30 s oder länger | Nichts: Der Druck ist ungültig |
| Beim Einschalten gehalten | Nichts, bis der Taster 1 s losgelassen war |

Bei einem Release- oder Development-Build lässt sich stattdessen `reset-network` auf der Konsole eingeben.

Die Hardwareprüfung des Taster-Jumpers an GPIO 4 lief noch nicht, den Reset also im Log bestätigen: Die Einheit startet neu und
protokolliert `no network settings: bench mode, commands come from the console`.

Ein Reset informiert das Backend nicht. Die alte Identität funktioniert im Backend weiter, bis **Provisionierung neu starten**
gewählt wird. Das also vor der erneuten Inbetriebnahme tun.

## WLAN ändern

**Bei jedem Build:**

1. [Einheit zurücksetzen](#einheit-zurücksetzen).
2. Beim Schrank im Admin-Panel **Provisionierung neu starten** wählen und das neue Token kopieren.
3. [Einheit erneut in Betrieb nehmen](/de/dokumentation/esp32/commissioning/).

Das Setup-Netzwerk öffnet sich nie bei einer Einheit mit Einstellungen, bei einem Production-Build gibt es also keinen kürzeren
Weg.

**Bei einem Release- oder Development-Build** lassen sich Identität, Zuordnung und Journal behalten. `python scripts/commission.py
--usb` ausführen. Es fragt nur nach WLAN-Name, Passwort und Broker-URL. Die gespeicherte Broker-URL bleibt, egal was eingegeben
wird: Sie ist eingefroren, sobald eine Einheit registriert ist.

## Firmware über USB aktualisieren

Das ist heute der empfohlene Update-Weg. Er behält Einstellungen, Identität, Zuordnung und Journal.

1. Die neue Version bauen.
2. Das [Image signieren](/de/dokumentation/esp32/flashing/#image-signieren), mit demselben Schlüssel wie das laufende Image, falls
   die Einheit später Over-the-Air-Updates annehmen soll.
3. [Flashen](/de/dokumentation/esp32/flashing/#firmware-flashen), ohne zu löschen.

## Firmware Over-the-Air aktualisieren

:::note[Geplant]
Over-the-Air-Updates sind noch nicht umgesetzt. Sie kommen mit [#336](https://github.com/Open-Locker/Open-Locker/pull/336), sobald das
Konzept für die Image-Signierung steht. Dieser Abschnitt beschreibt den Plan.
:::

:::caution[Lief noch nie auf einem Board]
Dieser Weg ist im Code vollständig, aber die Hardwaregruppe H lief noch nicht. Nur an einer Bench-Einheit ausprobieren und ein
USB-Kabel bereithalten.
:::

Die Einheit muss bereits ein signiertes, per USB geflashtes Image ausführen. Sie akzeptiert nur Images, die mit dem Schlüssel des
laufenden Images signiert sind
([ADR-0084](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0084-esp32-firmware-image-signing.md)).

1. Die neue Version mit ihrer Versionsnummer bauen und [signieren](/de/dokumentation/esp32/flashing/#image-signieren).
2. Im Admin-Panel unter **Firmware-Releases** eines anlegen. **Version** eingeben und das signierte `.bin` als **Firmware-Image**
   hochladen. Die Version muss zu der im Image passen.
3. Beim Schließfachschrank **Firmware aktualisieren** wählen, das **Firmware-Release** auswählen und **Update anfordern** wählen.
   **Downgrade erlauben** aus lassen, außer es soll wirklich zurückgehen.
4. **Firmware-Update** beim Schrank beobachten: Es geht von angefordert über Download zu abgeschlossen.

Die Einheit lädt das Image und bootet es zur Probe. Sie behält das Image nur, wenn WLAN, Broker und eine Busabfrage innerhalb von
180 Sekunden funktionieren. Sonst kehrt sie von selbst zum vorherigen Image zurück. Öffnen funktioniert währenddessen weiter, und ein
Update gibt nie ein Schloss frei.

Nur **abgeschlossen** bedeutet, dass es funktioniert hat. Bei **fehlgeschlagen** nennt die Bearbeitungsseite des Schranks Fehlercode und
Fehlergrund.

### Fehlgeschlagenes Update beheben

| Was passiert ist | Ergebnis | Was tun |
| --- | --- | --- |
| Fehlerhafter Download, fehlgeschlagene Signatur oder falsche Version | Das laufende Image bleibt | Image korrigieren und erneut versuchen |
| Das neue Image erreichte WLAN, Broker und Bus nicht innerhalb von 180 s | Die Einheit ist zurückgekehrt und lehnt diese Version künftig ab | Ein neues Release mit der nächsten Patch-Version veröffentlichen. Auch ein Netzausfall während des Neustarts kann das auslösen |
| Die Einheit bootet keines der Images, oder Journal oder Identität sind beschädigt | Die Einheit ist außer Betrieb | [Signiertes Image per USB flashen](/de/dokumentation/esp32/flashing/#firmware-flashen), dann erneut in Betrieb nehmen |

Ein verlorener oder kompromittierter Signaturschlüssel lässt sich nicht Over-the-Air beheben: Jede Einheit muss per USB geflasht werden.
Der Bootloader wird nie durch ein Update ersetzt.

## Schrank an den Raspberry-Pi-Client zurückgeben

1. Den ESP32 stromlos machen, dann das RS485-Paar vom Panel trennen.
2. Beim Schrank im Admin-Panel **Provisionierung neu starten** wählen und das neue Token kopieren. Die Identität des ESP32 funktioniert
   ab diesem Moment nicht mehr.
3. Den [Pi Client](/de/dokumentation/operations/) mit dem neuen Token starten. Das Backend sendet die Zuordnung erneut.

Um diesen ESP32 wieder zu nutzen, ihn zurücksetzen und mit einem neuen Token in Betrieb nehmen. Nie beide Controller an einem Panel
angeschlossen lassen.

## Einheit vollständig löschen

Das Löschen entfernt auch die Setup-Secrets und das Journal. [Flash löschen](/de/dokumentation/esp32/flashing/#flash-löschen) folgen, dann
neue Secrets und ein neues Etikett erzeugen.
