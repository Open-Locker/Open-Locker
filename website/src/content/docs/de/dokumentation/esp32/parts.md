---
title: Teile
description: Was für einen ESP32-gesteuerten Schließfachschrank benötigt wird.
sidebar:
  order: 2
---

Dies ist der Prüfaufbau, auf dem die Firmware entwickelt wird. Es wurde noch kein Produktionsboard
gewählt, die Liste gilt also für einen Testaufbau und ist keine Stückliste für einen Schrank.

## Controller-Teile

| Teil | Anforderung |
| --- | --- |
| ESP32 D1 mini | ESP-IDF-Target `esp32`, **4 MB Flash**, Chip **Revision 3.0 oder neuer**. Die Image-Signierung der Firmware braucht diese Revision, der Build setzt sie als Minimum. Das Flash-Layout setzt genau 4 MB voraus. [Firmware flashen](/de/dokumentation/esp32/flashing/#board-anschließen) zeigt, wie sich beides prüfen lässt |
| XY-485-Wandler | Automatische Richtungsumschaltung, es gibt also keinen DE/RE-Pin anzusteuern. Versorgung über 3V3 des ESP32 |
| USB-Datenkabel | Reine Ladekabel funktionieren nicht. Der USB-Seriell-Chip des Bench-Boards ist ein CH9102 |
| Taster | Schließer, von GPIO 4 nach GND. Der einzige Weg, einen Production-Build zurückzusetzen |

## Schlosspanel und Schlösser

| Teil | Anforderung |
| --- | --- |
| RS485-Schlosspanel | SY-800R, 8 Kanäle, Board-Adresse 1, mit Türrückmeldung. Das Protokoll steht in [ADR-0071](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md). Nur dieses Panel wurde ausprobiert, andere können abweichen |
| Schloss mit Türrückmeldung | Eines pro Kanal. Der Prüfaufbau nutzt eines auf Kanal 1. Das Panel steuert den Freigabeimpuls, die Firmware sendet nur Kommandos |
| Netzteil für das Panel | Das Panel hat eine eigene Versorgung |

**Noch nicht geklärt:** die Versorgungsspannung des Panels und das Schlossmodell. Die Spannung der
Dokumentation des Verkäufers für das eigene Panel entnehmen und prüfen, ob sie zu den Schlössern passt.

## Gemeinsame Schrankteile

Kabel, Aderendhülsen, Stecker und Verbindungsplatinen sind die der
[gemeinsamen Komponenten](/de/dokumentation/hardware/). Das dort aufgeführte Waveshare-Relaisboard und
die Raspberry-Pi-Teile entfallen: Das Schlosspanel ersetzt sie. Ob die 12-V-Schlösser und die
1N4007-Freilaufdioden aus dieser Liste zu diesem Panel passen, wurde nicht getestet.

## Benötigtes Werkzeug

- Ein Laptop mit Python 3 und entweder ESP-IDF 6.1 oder Docker. Siehe
  [Toolchain einrichten](/de/dokumentation/esp32/flashing/#toolchain-einrichten).
- Ein Multimeter, um vor dem Einschalten Panelversorgung und Polarität zu prüfen.
- Ein kleiner Schraubendreher für die Klemmen.

## Weiter

[Teile verkabeln](/de/dokumentation/esp32/wiring/).
