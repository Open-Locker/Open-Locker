---
title: Referenz
description: Konsolenbefehle, Tasterverhalten, Grenzen und Flash-Layout der ESP32-Firmware.
sidebar:
  order: 10
---

Alles hier ist gegen den Firmware-Quellcode geprüft. Der MQTT-Vertrag selbst steht in
[`docs/asyncapi/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs/asyncapi).

## Konsolenbefehle

Die Konsole läuft auf UART 0 über USB mit 115200 Baud. Ein Befehl pro Zeile, die mit CR, LF oder CRLF endet, höchstens 1024 Bytes.
Eine unvollständige Zeile wird nach 10 Sekunden Leerlauf verworfen. Die Eingabe wird nicht angezeigt, und keine Antwort wiederholt
einen gesendeten Wert.

| Befehl | Funktion | Antworten | Builds |
| --- | --- | --- | --- |
| `status` | Buszustand, Registrierung und Einstellungen | `ok bus=<state> enrolled=<0\|1> pending=<0\|1> configured=<0\|1>` | Alle |
| `diagnostics` | Buszustand, Freigaben auf der Leitung seit dem Boot, geringster freier Heap seit dem Boot, freier Stack des Owner-Tasks in Bytes | `ok bus=<state> unlock_transmissions=<n> min_free_heap=<n> owner_stack_free=<n>` | Alle |
| `self-test` | Prüft den Frame-Encoder. Sendet nichts an das Panel | `ok self-test passed`, `error self-test failed` | Alle |
| `query <board>` | Liest die Türen an den Adressen 0 bis 31: `c` geschlossen, `o` offen, `?` unbekannt | `ok doors=<32 characters>`, `error query no_valid_reply` | Alle |
| `unlock <board> <address>` | **Gibt dieses Schloss frei**, einmal, am Journal vorbei. Abgelehnt, sobald die Einheit registriert ist oder Einstellungen hat | `ok unlock acknowledged`, `error unlock not_sent`, `error unlock refused`, `error unlock outcome_unknown`, `error unlock disabled` | Release, Development |
| `command <json>` | Übergibt der Anwendung ein MQTT-Kommando, als käme es vom Broker. Abgelehnt, sobald Einstellungen gespeichert sind | `ok command queued`, `error command not accepted` | Release, Development |
| `configure <json>` | Was `commission.py --usb` sendet | `ok saved`, `error invalid_settings`, `error reset_required`, `error save_failed`, `error setup not accepted` | Release, Development |
| `reset-network` | Vergisst Einstellungen, Identität und Zuordnung und startet neu. Behält das Journal | `ok reset requested`, `error reset not accepted` | Release, Development |

`<state>` ist `connected`, `disconnected` oder `unreachable`. Ein Production-Build beantwortet seine vier fehlenden Befehle wie jedes
unbekannte Wort: `error invalid command`. Eine zu lange Zeile bekommt `error line too long`.

Im Bench-Modus gibt die Einheit außerdem aus, was sie veröffentlichen würde: `out response`, `out heartbeat`, `out snapshot`, `out event`
und `out connection`, jeweils gefolgt von JSON.

## Taster

Ein Taster von GPIO 4 nach GND, alle 20 ms abgefragt, mit internem Pull-up.

| Parameter | Wert |
| --- | --- |
| Haltedauer für einen Reset | 10 s bis unter 30 s, wirkt beim Loslassen |
| Ungültige Haltedauer | 30 s oder länger |
| Losgelassene Zeit, bevor ein Druck zählt | 1 s |
| Ignoriertes Prellen | Unter 50 ms |
| Abfragelücke, die einen Druck ungültig macht | Über 100 ms |
| Abgelehnter Reset wird erneut angeboten für | 2 s, dann protokolliert |

Der Build lehnt Strapping-, Flash-, Input-only-, Konsolen- und RS485-Pins für den Taster ab.

## Serielle Leitung und Timing

Das sind Standardwerte, änderbar mit `idf.py menuconfig`.

| Einstellung | Kconfig | Standard |
| --- | --- | --- |
| UART | `CONFIG_LC_RS485_UART_NUM` | 2 (1 oder 2 erlaubt) |
| TX-Pin | `CONFIG_LC_RS485_TX_PIN` | GPIO 17 |
| RX-Pin | `CONFIG_LC_RS485_RX_PIN` | GPIO 16 |
| Baudrate, Parität, Stoppbits | `CONFIG_LC_RS485_BAUD_RATE`, `CONFIG_LC_RS485_PARITY_*`, `CONFIG_LC_RS485_STOP_BITS` | 9600, keine, 1 |
| Antwortfrist | `CONFIG_LC_RS485_REPLY_DEADLINE_MS` | 1500 ms |
| Ruheintervall, das einen Frame beendet | `CONFIG_LC_RS485_QUIET_INTERVAL_MS` | 25 ms |
| Stille nach einem fehlgeschlagenen Austausch | `CONFIG_LC_RS485_SETTLE_PERIOD_MS` | 3000 ms |
| Fehler vor einer Abkühlphase | `CONFIG_LC_RS485_FAILURES_BEFORE_COOLDOWN` | 5 |
| Abkühlphase | `CONFIG_LC_RS485_COOLDOWN_MS` | 60000 ms |
| Pin des Reset-Tasters | `CONFIG_LC_RESET_BUTTON_GPIO` | GPIO 4 |

Türen werden alle 500 ms abgefragt, sobald eine Zuordnung angewendet ist. Nach drei fehlgeschlagenen Lesevorgängen meldet sich eine Tür als
unbekannt.

## Setup-Netzwerk

| Parameter | Wert |
| --- | --- |
| Offen für | 10 Minuten nach dem Einschalten, bei einer Einheit ohne Einstellungen und nicht registriert |
| Schließt sich | Nach dem Speichern, nach 10 Minuten oder nach 5 fehlgeschlagenen Sitzungen |
| Öffnet sich wieder | Beim nächsten Boot, falls weiterhin keine Einstellungen vorhanden sind |
| Name | `OpenLocker-` und 4 Zufallszeichen, vom Etikett |
| Sicherheit | WPA2 mit 16-stelliger Passphrase und Security 2 mit 12-stelligem Setup-Passwort |
| Stationen | Eine zur Zeit, auf Kanal 1 |
| Adresse | `192.168.4.1:80` |

Die Grenzen von `commission.py` für Werte:

| Wert | Grenze |
| --- | --- |
| WLAN-Name | 1 bis 32 Bytes |
| WLAN-Passwort | Leer, oder 8 bis 63 Zeichen im Setup-Netzwerk und 8 bis 64 über USB |
| Broker-URL | Bis 192 Bytes, `mqtts://` (oder `mqtt://` bei einem Development-Build), Standardports 8883 und 1883 |
| Bootstrap-Benutzer, Bootstrap-Passwort, Token | Je 1 bis 128 Bytes |

## Fehlercodes für Kommandos

| Code | Bedeutung |
| --- | --- |
| `MISSING_MESSAGE_ID` | Das Kommando hat keine `message_id` |
| `INVALID_COMMAND` | Fehlerhafter Zeitstempel, `data` ist kein Objekt, oder ein Feld fehlt |
| `UNKNOWN_ACTION` | Die Aktion ist unbekannt |
| `INVALID_CONFIG` | `apply_config` hat die Prüfung nicht bestanden: falscher Hash, doppelte Nummer oder Adresse, eine zweite Board-Adresse, ein anderer `adapter_type` oder zu viele Fächer |
| `RUNTIME_CONFIG_NOT_APPLIED` | Öffnen vor jeder Zuordnung |
| `COMPARTMENT_NOT_FOUND` | Öffnen eines nicht zugeordneten Fachs |
| `HARDWARE_ERROR` | Die Freigabe wurde nicht gesendet, oder ihr Ergebnis ist unbekannt |
| `SHUTTING_DOWN` | Die Einheit fährt herunter, zum Beispiel nach dem Speichern neuer Einstellungen |
| `UNKNOWN_ERROR` | Alles andere, zum Beispiel zu viele wartende Antworten |
| `UPDATE_INVALID` | Ein fehlerhaftes Update-Kommando, eine URL ohne HTTPS oder eine über 256 Zeichen |
| `UPDATE_BUSY` | Eine Tür wird beobachtet, oder ein Update läuft |
| `UPDATE_NOT_READY` | Journal unhealthy, Bus nicht verbunden, Image in der Probephase oder laufende Version nicht vergleichbar |
| `UPDATE_SAME_VERSION` | Die Einheit führt diese Version bereits aus |
| `UPDATE_DOWNGRADE` | Eine niedrigere Version ohne `allow_downgrade` |
| `UPDATE_VERSION_REJECTED` | Die Einheit ist von dieser Version zurückgekehrt |
| `UPDATE_DOWNLOAD_FAILED` | Als Ereignis gemeldet, wenn der Download eines angenommenen Updates fehlschlägt |
| `UPDATE_VERIFY_FAILED` | Als Ereignis gemeldet, wenn die Prüfung eines angenommenen Updates fehlschlägt |

Die `UPDATE_*`-Codes sind mit den Over-the-Air-Updates geplant ([#336](https://github.com/Open-Locker/Open-Locker/pull/336)) und noch nicht
umgesetzt.

## Grenzen

- **Keine Produktiveinheit darf ausgeliefert werden.** Flash- und NVS-Verschlüsselung sind aus, die Partition `nvs_keys` ist für später
  reserviert. Zugangsdaten und Setup-Secrets liegen unverschlüsselt auf dem Chip.
- **Das Journal merkt sich die neuesten 256 zugestellten Kommandos**, `apply_config` eingeschlossen. Eine später eintreffende ältere Kopie
  eines Kommandos läuft erneut.
- **Höchstens 32 Antworten können auf den Broker warten.** Neue Kommandos werden dann mit `UNKNOWN_ERROR` abgelehnt, und das Zustellen der
  gespeicherten Antworten hebt die Grenze auf.
- **Ein Schub von mehr als etwa 30 Kommandos in 3 s kann abgelehnt werden.** Nach einer Kompaktierung wird der freie Journal-Bereich sektorweise
  gelöscht, je ein Sektor pro untätigem 100-ms-Tick.
- **Der Boot kann den freien Journal-Bereich löschen**, bevor Kommandos angenommen werden, falls er Einträge enthält: bis zu 32 Sektorlöschungen,
  etwa 1,5 s und im schlimmsten Fall 16 s.
- **Ein unhealthy Journal stoppt jede Betätigung.**
- **Ein Panel pro Bus.** Eine zweite Board-Adresse wird abgelehnt, ebenso jeder `adapter_type` außer `rs485_lock_board`. Bis zu 64 Fächer lassen
  sich zuordnen, das Bench-Panel hat aber 8 Kanäle.
- **Das Broker-Zertifikat muss auf eine öffentliche CA** im eingebauten Bundle zurückgehen. Zertifikats- und Hostnamen-Prüfung lassen sich nicht
  abschalten. Eine private CA muss in den Build aufgenommen werden.
- **Ein Kommando ohne freien Slot wird verworfen und protokolliert**, wenn alle 32 Slots belegt sind, und das Backend läuft in den Timeout.
- **Nicht geklärt:** das Schlossmodell sowie Modell und Kenndaten des Spannungswandlers.

Die Anzahlen für Kommandos, Antworten und Fächer oben sind Einstellungen der Firmware, keine Grenzen des Chips. Ein Chip mit mehr Flash oder RAM würde sie
also nicht von selbst erhöhen. Kommandos laufen außerdem nacheinander, eine längere Warteschlange würde das letzte Kommando also nur später ausführen.

## Flash-Layout

Das Layout ist fest ([ADR-0078](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0078-esp32-flash-layout.md)) und lässt sich nicht
Over-the-Air ändern. Es nutzt die ersten 4 MB des Flash. Ein größerer Chip sollte es ausführen und den Rest ungenutzt lassen, an einem Board wurde das
aber noch nicht ausprobiert. Die Partitionstabelle liegt bei `0xC000`, der Bootloader beginnt bei `0x1000`.

| Partition | Offset | Größe | Inhalt |
| --- | --- | --- | --- |
| `nvs` | `0xD000` | 24 KiB | Einstellungen, Identität, Zuordnung und Setup-Secrets |
| `otadata` | `0x13000` | 8 KiB | Welcher App-Slot bootet. Leer bedeutet `ota_0` |
| `phy_init` | `0x15000` | 4 KiB | Funkkalibrierung |
| `nvs_keys` | `0x16000` | 4 KiB | Für NVS-Verschlüsselung reserviert, ungenutzt |
| `ota_0` | `0x20000` | 1856 KiB | Firmware. Ein USB-Flash schreibt sie hierher |
| `ota_1` | `0x1F0000` | 1856 KiB | Zweiter Slot, von einem Update geschrieben |
| `journal` | `0x3C0000` | 256 KiB | Das Kommando-Journal, roh, Subtyp `0x40` |

Die [interaktive Flash- und Speicherkarte](/de/dokumentation/esp32/memory/) zeigt den Platzbedarf eines Images in Production-Konfiguration und den
Unterschied zwischen reservierter Kapazität und gemessener Nutzung.

## Für Entwickler:innen

- [ESP32-Firmware entwickeln](/de/dokumentation/esp32-development/): Builds, Gates, Host-Tests und wie ein Test hinzukommt.
- [Hardware-Abnahme](/de/dokumentation/esp32-hardware-acceptance/): die Bench-Prüfungen und ihr Nachweis.
- [ESP32-Firmware-Interna](/de/dokumentation/esp32-internals/): Schichten, Ports, Task-Modell, Journal-Format, Setup-Netzwerk, Update-Reihenfolge,
  Rollout-Status.
- Entscheidungen: [ADR-0072](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0072-esp32-locker-firmware.md) (Firmware),
  [ADR-0070](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0070-esp32-command-journal-format.md) (Journal),
  [ADR-0071](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md) (Panel),
  [ADR-0074](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0074-esp32-softap-provisioning.md) (Setup-Netzwerk),
  [ADR-0075](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0075-esp32-ota-firmware-updates.md) (Updates).
