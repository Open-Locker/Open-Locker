---
title: Controller wählen
description: Raspberry Pi oder ESP32 — welcher Locker-Controller zum Aufbau passt und wie ausgereift beide sind.
sidebar:
  order: 1
---

Für jeden Schließfachschrank, auf den sich Menschen verlassen, den Raspberry Pi verwenden. Die
ESP32-Firmware ist für einen beaufsichtigten Prüfaufbau gedacht: ausprobieren, testen und mit
fertigstellen, aber nicht in einem Schrank im Feld einbauen.

:::danger[Keine Produktiveinheit darf ausgeliefert werden]
Flash- und NVS-Verschlüsselung sind in jedem Build aus. Das WLAN-Passwort, die MQTT-Zugangsdaten
und die Setup-Secrets liegen deshalb unverschlüsselt auf dem Chip. Wer die Einheit erreicht, kann
sie auslesen. Eine geflashte Einheit nicht unbeaufsichtigt lassen.
:::

## Raspberry Pi oder ESP32?

| | Raspberry Pi (`locker-client/`) | ESP32 (`locker-firmware/`) |
| --- | --- | --- |
| **Status** | Der [validierte Aufbau](/de/dokumentation/hardware/) | Bench-Firmware |
| **Schlosshardware** | Waveshare-Modbus-Relaisboard oder RS485-Schlosspanel | Nur RS485-Schlosspanel, ein Panel pro Bus |
| **Läuft als** | Docker-Image unter Linux | Firmware auf einem ESP32 mit mindestens 4 MB Flash |
| **Verbindet sich über** | [Client-Konfiguration und Token](/de/dokumentation/operations/) | Setup-WLAN vom Laptop aus, oder USB bei Bench-Builds |
| **Updates** | Neues Image-Tag ziehen | Per USB neu flashen. Over-the-Air-Updates sind im Code vorhanden, liefen aber noch auf keinem Board |
| **Zugangsdaten im Ruhezustand** | Im Dateisystem des Hosts | Unverschlüsselt auf dem Chip |
| **Dauerbetrieb** | Im Einsatz an Testschränken; der Pi fiel in unseren Tests gelegentlich aus | Der 72-Stunden-Lauf steht noch aus |

Preis und Stromverbrauch wurden für keinen der beiden Controller gemessen und werden hier deshalb
nicht verglichen.

## Was der ESP32-Controller macht

Der ESP32 macht für ein RS485-Schlosspanel das, was der Pi Client macht. Er nimmt die Kommandos
`open_compartment` und `apply_config` vom Broker entgegen, gibt das Schloss frei, beobachtet die Tür
und meldet den Zustand. Er spricht denselben
[MQTT-Vertrag](https://github.com/Open-Locker/Open-Locker/tree/main/docs/asyncapi), das Backend weiß
also nicht, mit welchem der beiden es spricht. Ein Schrank wird von genau einem der beiden gesteuert,
nie von beiden
([ADR-0069](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0069-esp32-locker-firmware.md)).

## Reifegrad der ESP32-Firmware: Bench

Die automatischen Gates laufen ohne Hardware durch. Die Prüfungen an echter Hardware haben noch kein
abgezeichnetes Ergebnis.

| Hardwaregruppe | Inhalt | Stand |
| --- | --- | --- |
| A | Serieller Bus und Panel | Kein abgezeichneter Lauf. Panelmodell, Adresse und serielle Einstellungen wurden am Prüfaufbau bestätigt |
| B | Kommandos und Journal | Kein abgezeichneter Lauf |
| C | Netzwerk, MQTT und Enrollment | Kein abgezeichneter Lauf. Keine Prüfung deckt bisher eine verifizierte `mqtts://`-Verbindung ab, weil der Bench-Broker kein TLS hat |
| D | Zustand und Türereignisse | Kein abgezeichneter Lauf |
| E | USB-Inbetriebnahme | Kein abgezeichneter Lauf |
| F | Inbetriebnahme über das Setup-WLAN | Nicht bestanden. Ein Lauf auf einem Development-Build führte von einer gelöschten Einheit bis zum Öffnen aus dem Admin-Panel. Die Prüfung des Taster-Jumpers an GPIO 4 lief noch nicht |
| G | Dauerbetrieb | Nicht gelaufen |
| H | Over-the-Air-Updates | Auf keinem Board gelaufen |

Die Prüfungen selbst stehen unter [Hardware-Abnahme](/de/dokumentation/esp32-hardware-acceptance/).

## Der Weg durch diese Seiten

1. [Teile](/de/dokumentation/esp32/parts/): was benötigt wird.
2. [Verkabelung](/de/dokumentation/esp32/wiring/): ESP32, Wandler, Panel und Schloss verbinden.
3. [Firmware flashen](/de/dokumentation/esp32/flashing/): bauen und auf den Chip bringen.
4. [Bench-Test](/de/dokumentation/esp32/bench-test/): Bus und ein Schloss ohne Backend prüfen.
5. [Inbetriebnahme](/de/dokumentation/esp32/commissioning/): Einheit mit dem WLAN verbinden und registrieren.
6. [End-to-End prüfen](/de/dokumentation/esp32/verify/): ein Fach aus Admin-Panel und App öffnen.

Sobald eine Einheit läuft, helfen [Warten und zurücksetzen](/de/dokumentation/esp32/maintenance/),
[Fehlerbehebung](/de/dokumentation/esp32/troubleshooting/) und die
[Referenz](/de/dokumentation/esp32/reference/). Für die Arbeit an der Firmware selbst geht es bei
[ESP32-Firmware entwickeln](/de/dokumentation/esp32-development/) los.
