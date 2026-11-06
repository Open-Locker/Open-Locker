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
| ESP32 D1 mini | ESP-IDF-Target `esp32`, **mindestens 4 MB Flash**, Chip **Revision 3.0 oder neuer**. Siehe [Chip wählen](#chip-wählen). [Firmware flashen](/de/dokumentation/esp32/flashing/#board-anschließen) zeigt, wie sich beides prüfen lässt |
| XY-485-Wandler | Automatische Richtungsumschaltung, es gibt also keinen DE/RE-Pin anzusteuern. Versorgung über 3V3 des ESP32 |
| USB-Datenkabel | Reine Ladekabel funktionieren nicht. Der USB-Seriell-Chip des Bench-Boards ist ein CH9102 |
| Taster | Schließer, von GPIO 4 nach GND. Der einzige Weg, einen Production-Build zurückzusetzen |

## Chip wählen

Die Firmware braucht einen klassischen ESP32 (Target `esp32`) mit **Revision 3.0 oder neuer** und
**mindestens 4 MB Flash**. Sie lief bisher nur auf einem Chip der Revision 3.1 mit 4 MB. Das
[Flash-Layout](/de/dokumentation/esp32/reference/#flash-layout) nutzt die ersten 4 MB. Ein größerer Chip
sollte es also ausführen und den Rest ungenutzt lassen, an einem Board wurde das aber noch nicht ausprobiert.

| Chip oder Modul | Verwenden? |
| --- | --- |
| [ESP32-WROOM-32E](https://documentation.espressif.com/esp32-wroom-32e_esp32-wroom-32ue_datasheet_en.html), ESP32-D0WD-V3, ESP32-U4WDH | Ja. Sie erfüllen das Minimum, wurden aber noch nicht ausprobiert |
| ESP32 D1 mini | Erst prüfen. Der Prüfaufbau nutzt eines, aber wir konnten nicht bestätigen, mit welcher Chip-Revision und Flash-Größe diese Boards ausgeliefert werden. Vor dem Einsatz `flash-id` ausführen |
| [ESP32-WROOM-32](https://documentation.espressif.com/esp32-wroom-32_datasheet_en.html), ESP32-D0WDQ6-V3 | Für neue Aufbauten vermeiden. [Espressif](https://documentation.espressif.com/esp32_datasheet_en.html) markiert beide als nicht für neue Designs empfohlen |
| Ein Chip unter Revision 3.0 | Nein. [Die Image-Signierung braucht Revision 3.0](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/security/secure-boot-v2.html), und der Build erzwingt sie |

### Kandidat für ein neues Board

Wer ein Board entwirft, sollte sich das
[ESP32-S3-WROOM-1-N16](https://documentation.espressif.com/esp32-s3-wroom-1_wroom-1u_datasheet_en.pdf)
ansehen oder die 1U-Version mit Anschluss für eine externe Antenne. Das ist eine Empfehlung, keine Entscheidung.
**Die Firmware lässt sich für den S3 kompilieren, lief aber noch nie auf einem**, und
[weitere Arbeit ist bekannt](/de/dokumentation/esp32-development/#auf-einen-anderen-chip-portieren).

- **Schlüsselwechsel:** Ein klassischer ESP32 hält einen Signierschlüssel, ein kompromittierter Schlüssel bedeutet also
  einen USB-Besuch an jeder Einheit
  ([ADR-0084](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0084-esp32-firmware-image-signing.md)).
  Der S3 [speichert bis zu drei Schlüssel und kann sie widerrufen](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32s3/api-reference/system/ota.html),
  sobald Hardware-Secure-Boot aktiv ist.
- **Kein PSRAM:** Die Firmware braucht keinen. Module mit 8 MB Octal-PSRAM (`R8`) sind standardmäßig bis 65 °C
  spezifiziert, das N16 bis 85 °C.
- **16 MB Flash:** Das lässt später Platz für ein größeres Journal oder Image. Aktuelle Preise mit der 8-MB-Version
  vergleichen, da wir keine verlässlichen gefunden haben.
- **Externe Antenne:** Die 1U-Version scheint in einem Metallschrank die sicherere Wahl. Das ist eine Einschätzung und
  wurde nicht getestet.

### Chips vergleichen

Neuere Chips bieten mehr Flash und PSRAM, aber nicht mehr On-Chip-RAM.

| Chip | On-Chip-RAM | Flash | PSRAM | WLAN | Verfügbar mindestens bis |
| --- | --- | --- | --- | --- | --- |
| [ESP32](https://documentation.espressif.com/esp32_datasheet_en.html) | 520 KB | Bis 16 MB | Bis 8 MB, davon 4 MB gleichzeitig eingeblendet | 2,4 GHz | 2031 |
| [ESP32-C3](https://documentation.espressif.com/esp32-c3_datasheet_en.html) | 400 KB | Bis 16 MB | Keiner angegeben | 2,4 GHz | 2033 |
| [ESP32-S3](https://documentation.espressif.com/esp32-s3-wroom-1_wroom-1u_datasheet_en.pdf) | 512 KB | Module mit 4, 8 oder 16 MB | Bis 16 MB | 2,4 GHz | 2033 |
| [ESP32-C6](https://documentation.espressif.com/esp32-c6_datasheet_en.html) | 512 KB | Bis 16 MB | Keiner angegeben | Wi-Fi 6, 2,4 GHz | 2035 |
| [ESP32-C5](https://documentation.espressif.com/esp32-c5_datasheet_en.html) | 384 KB | Bis 32 MB, gemeinsam mit PSRAM | Bis 32 MB, gemeinsam mit Flash | Wi-Fi 6, 2,4 und 5 GHz | 2037 |

Die Jahre addieren die Zusage zum Startdatum in
[Espressifs Longevity-Tabelle](https://www.espressif.com/en/products/longevity-commitment), die Espressif ändern kann.
Der C3 ist günstiger, hat aber weniger RAM und einen Kern mit 160 MHz. C5 und C6 lohnen sich nur, wenn Wi-Fi 6 oder,
beim C5, 5 GHz gebraucht wird.

## Schlosspanel und Schlösser

| Teil | Anforderung |
| --- | --- |
| RS485-Schlosspanel | SY-800R, 8 Kanäle, Board-Adresse 1, mit Türrückmeldung. Das Protokoll steht in [ADR-0071](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md). Nur dieses Panel wurde ausprobiert, andere können abweichen |
| Schloss mit Türrückmeldung | Eines pro Kanal. Der Prüfaufbau nutzt eines auf Kanal 1. Das Panel steuert den Freigabeimpuls, die Firmware sendet nur Kommandos |
| Netzteil | 12 V am Prüfaufbau. Das Panel hängt direkt daran |
| Spannungswandler | Verbindet den ESP32 mit dem 12-V-Netzteil. Seine Ausgangsspannung muss zum Versorgungspin des ESP32-Boards passen. Siehe [Stromversorgung](/de/dokumentation/esp32/wiring/#stromversorgung) |

**Noch nicht geklärt:** das Schlossmodell sowie Modell und Kenndaten des Spannungswandlers. Prüfen, ob die
Schlösser für das eigene Netzteil spezifiziert sind, und bei einem anderen Panel die Dokumentation des Verkäufers nutzen.

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
