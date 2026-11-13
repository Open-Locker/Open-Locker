---
title: ESP32-Firmware entwickeln
description: locker-firmware einrichten, bauen und testen sowie Tests, Komponenten, Ports und Kommandos hinzufügen.
---

Vor dem Pushen ein Befehl: `python scripts/verify.py` in `locker-firmware/`. Er führt jedes Gate aus, das keine Hardware braucht. Diese Seite
behandelt, was zum Ändern der Firmware nötig ist. Nur zum Flashen einer Einheit siehe
[Firmware flashen](/de/dokumentation/esp32/flashing/).

- Aufbau des Codes, Formate und Reihenfolgen: [ESP32-Firmware-Interna](/de/dokumentation/esp32-internals/)
- Prüfungen an einem echten Board: [Hardware-Abnahme](/de/dokumentation/esp32-hardware-acceptance/)

Die Firmware ist C auf ESP-IDF, festgelegt auf genau v6.1
([ADR-0072](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0072-esp32-locker-firmware.md)), und `dependencies.lock` ist eingecheckt. Ein
neues ESP-IDF-Patch-Release kommt über einen Pull Request und einen Bench-Lauf, gemeinsam in Firmware, CI, Docker-Image und Host-Werkzeugen.

## Einrichten

Aus `locker-firmware/`:

```bash
pip install -r requirements.txt -r requirements-dev.txt
```

| Ziel | Benötigt |
| --- | --- |
| Host-Gates ausführen (`--skip-target`) | Python 3 mit `jsonschema`, CMake 3.20 oder neuer und clang oder gcc mit gcov (oder `llvm-cov`). Der erste Lauf lädt Unity und cJSON |
| Target-Build und IDF-Host-Tests ausführen | Eine ESP-IDF-6.1-Shell oder das Docker-Image `espressif/idf:v6.1` |
| Flashen und in Betrieb nehmen | ESP-IDF und `requirements.txt` |

Unter Windows `CMAKE_GENERATOR` und `CC` setzen (zum Beispiel `MinGW Makefiles` und `gcc`), falls CMake keinen Compiler findet.

Ohne lokale Toolchain läuft alles im Container. `requirements-dev.txt` ist nicht Teil des Images und wird deshalb im selben Befehl installiert. Der
Container läuft als root, der letzte Schritt gibt die Dateien also zurück:

```bash
docker run --rm -v "$PWD/..:/repo" -w /repo/locker-firmware espressif/idf:v6.1 \
  bash -c "pip install -q -r requirements.txt -r requirements-dev.txt && python scripts/verify.py --skip-target; status=\$?; chown -R $(id -u):$(id -g) .; exit \$status"
```

Ein CMake-Build-Verzeichnis ist an den Ort seiner Konfiguration gebunden. Beim Wechsel zwischen Container und lokaler Toolchain also `build/verify` löschen.

## Build-Konfiguration wählen

| Build | Befehl | Unterschied |
| --- | --- | --- |
| Release | `idf.py build` | Der Standard: nur `mqtts://` |
| Development | `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.dev" build` | `CONFIG_LC_ALLOW_PLAINTEXT_MQTT=y`, erlaubt `mqtt://` und warnt bei jedem Verbinden |
| Production | `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.production" build` | `CONFIG_LC_BENCH_CONSOLE=n`: kein `configure`, `unlock`, `command` oder `reset-network`. Noch nicht freigabefähig, weil Flash- und NVS-Verschlüsselung aus sind |

Beim Wechsel `sdkconfig` löschen, sonst bleiben die alten Werte.

`ESP_TLS_INSECURE` und `ESP_TLS_SKIP_SERVER_CERT_VERIFY` sind in jeder Konfiguration ein Build-Fehler
([ADR-0079](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0079-esp32-mqtt-tls-only-in-release-builds.md)). Eine Version wird mit
`LC_FIRMWARE_VERSION=0.2.0` gesetzt, danach `idf.py reconfigure`, falls das Projekt schon konfiguriert ist. Ohne sie ist der Build `0.0.0-dev`.

Pins, serielle Parameter und Panel-Timing sind Kconfig-Optionen, änderbar mit `idf.py menuconfig`. Siehe die
[Referenz](/de/dokumentation/esp32/reference/#serielle-leitung-und-timing).

## Gates ausführen

```bash
python scripts/verify.py                 # alles; braucht eine ESP-IDF-Shell
python scripts/verify.py --skip-target   # ohne ESP-IDF
```

`--skip-target` endet mit `PASSED, ESP-IDF checks NOT RUN`, ein vollständiger Lauf mit `PASSED`. Jede Prüfung erscheint unter `== <name>`:

| Prüfung | Schlägt fehl bei |
| --- | --- |
| `test IDs` | Einer katalogisierten ID eines umgesetzten Bereichs ohne Test, einem Test mit einer ID, die der Katalog nicht nennt, einem definierten, aber nie ausgeführten oder doppelt ausgeführten Test |
| `layer includes` | Einem verbotenen Include oder `REQUIRES`, einem SDK-Header in einer reinen Komponente oder einer Komponente, die in keiner Liste von `verify.py` steht |
| `OTA settings` | Geplant mit [#336](https://github.com/Open-Locker/Open-Locker/pull/336). Einer sdkconfig-Datei, die `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` setzt |
| `static analysis` | Einem Befund des clang-Analyzers oder von `gcc -fanalyzer` in den reinen Quellen |
| `host tests` | Einer Suite, gebaut mit AddressSanitizer und UndefinedBehaviorSanitizer |
| `message schemas` | Einer von einem Test kodierten Nachricht, die gegen `docs/asyncapi/schemas` ungültig ist |
| `tool tests` | Einem Python-Test eines Werkzeugs in `scripts/` |
| `line coverage` | Einer reinen Komponente unter 95 % |
| `target build` | Einem fehlgeschlagenen Build, einer App plus 4-KiB-Signaturblock, die den kleineren OTA-Slot überschreitet, oder einem Bootloader, der seine 44 KB überschreitet |
| `IDF host tests` | Einem fehlgeschlagenen Test der ESP-IDF-Adapter auf dem Linux-Target |

CI (`.github/workflows/locker-firmware-ci.yml`) führt `--skip-target` aus, dann den Target-Build, die Image-Größenprüfung
(`python scripts/verify.py --check-image-sizes build`), die IDF-Host-Tests, eine Prüfung, dass unverschlüsseltes MQTT im Standard-Build aus ist, und
`python scripts/hwtest.py check`. Der letzte Schritt schlägt fehl, solange es keinen aktuellen
[Hardware-Nachweis](/de/dokumentation/esp32-hardware-acceptance/#der-nachweis-den-ci-prüft) gibt.

## Einen Host-Test ausführen

`verify.py` konfiguriert das Host-Projekt in `build/verify/host`. Danach läuft eine einzelne Suite so:

```bash
cmake --build build/verify/host --target test_button
ctest --test-dir build/verify/host -R test_button --output-on-failure
```

Im Container:

```bash
docker run --rm -v "$PWD/..:/repo" -w /repo/locker-firmware espressif/idf:v6.1 \
  bash -c "cmake --build build/verify/host --target test_button && ctest --test-dir build/verify/host -R test_button --output-on-failure; status=\$?; chown -R $(id -u):$(id -g) .; exit \$status"
```

Host-Suites kompilieren die echten Komponentenquellen. Nur Ports werden gefälscht, und die Fakes in `tests/host/fakes/` protokollieren die
Aufrufreihenfolge. Reihenfolgeregeln werden also geprüft, nicht angenommen.

Die IDF-Host-Tests liegen in `tests/idf_host/` und testen die SDK-Adapter (`lc_mqtt_espidf`, `lc_ota_espidf`) gegen CMock-Mocks aus den echten
SDK-Headern. Der MQTT-Mock nimmt seinen Header aus `managed_components/`, die Firmware also zuerst bauen.

```bash
cd tests/idf_host
idf.py --preview -DIDF_TARGET=linux build
./build/idf_host_test.elf
```

## Einen Test hinzufügen

Der Katalog ist `locker-firmware/docs/testing.md`. Neue Arbeit trägt ihre Tests zuerst dort ein, unter einem neuen Präfix `S<n>`, und schreibt sie vor
dem Code.

1. Eine Zeile im Katalog ergänzen, zum Beispiel `| S8-H01 | Was der Test beweist |`.
2. Den Test in der Suite des Bereichs in `tests/host/` schreiben, eine Funktion pro Test. Die ID steht im Namen, mit Unterstrich statt Bindestrich:

   ```c
   void test_S8_H01_what_the_test_proves(void)
   {
       TEST_ASSERT_TRUE(...);
   }
   ```

   Ein Python-Werkzeugtest nutzt dieselbe Form: `def test_S8_H01_what_it_proves(self):` in `scripts/test_*.py`.

3. `RUN_TEST(test_S8_H01_what_the_test_proves);` im `main` der Suite ergänzen. `verify.py` schlägt bei einem definierten, aber nie ausgeführten Test fehl.
4. Für eine neue Suite eine `test_<name>.c` mit `setUp` und `tearDown` von Unity anlegen und `lc_host_test(test_<name>)` in `tests/host/CMakeLists.txt`
   ergänzen.
5. Kodiert ein Test eine MQTT-Nachricht, sie mit dem Schema-Pfad unterhalb von `docs/asyncapi/schemas` an `emit_message()` übergeben. So wird sie gegen
   den Vertrag geprüft.
6. Besteht der ganze Bereich, seine Nummer zu `IMPLEMENTED_SLICES` in `scripts/verify.py` hinzufügen.

Tests für Details, die der Katalog nicht nennt, tragen keine ID. Die meisten liegen in den `*_edge`-Suites.

Weitere Regeln:

- Ein Test, der gelegentlich fehlschlägt, ist ein fehlschlagender Test.
- Einen Test nicht ohne Zeile im PR entfernen oder überspringen, die den Grund nennt. Für einen geänderten Katalogtest gilt dasselbe.
- Lässt sich ein Hardwarefehler ohne Hardware reproduzieren, bringt seine Behebung einen Host-Test mit.

Der `ol-config`-Parser hat außerdem ein libFuzzer-Target in `tests/fuzz/fuzz_ol_config.c`. Das Host-Gate kompiliert es, führt es aber nicht aus. Die Datei
enthält den clang-Befehl, um es von Hand zu bauen.

## Eine Komponente hinzufügen

1. `components/lc_<name>/` mit `CMakeLists.txt` anlegen und nur die `REQUIRES`, die ihre Schicht erlaubt. Siehe
   [Schichten](/de/dokumentation/esp32-internals/#schichten-und-komponenten).
2. Sie zu `PURE_COMPONENTS`, `HEADER_ONLY_PURE_COMPONENTS` oder `ADAPTER_COMPONENTS` in `scripts/verify.py` hinzufügen, sonst schlägt die Schichtprüfung fehl.
   Eine Komponente, die nicht in `ALLOWED_DEPENDENCIES` steht, darf von `lc_domain` und `lc_ports` abhängen. Nur für eine andere Regel dort eintragen.
3. Ist sie rein, ihre Quellen und ihren Include-Pfad zu `lc_under_test` in `tests/host/CMakeLists.txt` hinzufügen. Ihre Zeilenabdeckung zählt dann zu den 95 %.
4. Sie in `main/app_main.c` an ihren Port anschließen, dem einzigen Ort, der Adapter erzeugt.

## Einen Port hinzufügen

1. Das Struct aus Funktionszeigern mit seinem `ctx` in `components/lc_ports/include/lc_ports/` anlegen.
2. Es zum `ports`-Struct in `components/lc_application/include/lc_application/app.h` hinzufügen.
3. Einen Fake, der seine Aufrufe protokolliert, in `tests/host/fakes/fake_ports.c` ergänzen und in `app_fixture.c` setzen.
4. Ihn in einer Adapter-Komponente umsetzen und aus einer Funktion wie `lc_ota_port()` zurückgeben.
5. Ihn in `main/app_main.c` setzen.

## Ein MQTT-Kommando hinzufügen

`update_firmware` ist das Vorbild, sobald es mit [#336](https://github.com/Open-Locker/Open-Locker/pull/336) kommt.

1. Das ADR schreiben, dann den Vertrag in `docs/asyncapi/`: Kommando- und Antwortschemata in `schemas/payloads/`, ihre Dateien in `examples/` und die
   Nachrichten in `mqtt.yaml`.
2. Die Aktion zu `lc_action_t` in `lc_domain/command.h` hinzufügen, mit einem Struct für ihre Daten, und neue Fehlercodes zu `lc_domain/errors.h`.
3. Sie in `lc_mqtt_codec/codec.c` parsen und in `tests/host/test_mqtt_codec.c` testen: Jedes Kommandobeispiel in `docs/asyncapi/examples/` muss sich parsen lassen.
4. Einen `case` in `lc_application/handle_command.c` ergänzen, das das Kommando zu diesem Zeitpunkt schon im Journal beansprucht hat. Den Use Case in eine eigene
   Datei in `lc_application/` legen und diese zur `CMakeLists.txt` der Komponente und zu `lc_under_test` in `tests/host/CMakeLists.txt` hinzufügen.
5. Den Use Case über `lc_app_handle_command` mit den Fakes testen, wie `tests/host/test_firmware_update.c`, und kodierte Antworten an `emit_message()` übergeben.
6. Im Backend einen typisierten Publisher in `app/Mqtt/Publishers/` ergänzen und den Zustand aus der Antwort ableiten.

## Auf einen anderen Chip portieren

Die Firmware läuft nur auf dem klassischen ESP32, und die CI baut nur für `esp32`. Ein Chipwechsel ist eine Architekturentscheidung und braucht ein ADR. Mehr
Speicher ist kein Grund zum Portieren: Die [Grenzen der Firmware sind ihre eigenen Einstellungen](/de/dokumentation/esp32/reference/#grenzen), und das Image lässt
etwa 38 % seines Slots frei. Die Kandidaten stehen unter [Chip wählen](/de/dokumentation/esp32/parts/#chip-wählen).

Ein Testbuild mit ESP-IDF 6.1 ohne Code-Änderungen ließ sich für beide dieser Targets kompilieren. Keiner lief auf einem Chip, das zeigt also nur, dass der Code
kompiliert.

| Target | Build | Image-Größe |
| --- | --- | --- |
| `esp32s3` | Erfolgreich | `0x120000` |
| `esp32c3` | Erfolgreich | `0x140000` |

Bekannte Arbeit, bevor die Firmware auf einem anderen Chip laufen könnte:

- **Flash-Befehle:** Die Dokumentation legt den Bootloader auf `0x1000`. Auf S3 und C3 beginnt er bei `0x0`.
- **Serieller Port:** `LC_RS485_UART_NUM` steht standardmäßig auf UART 2, den
  [C3](https://documentation.espressif.com/esp32-c3_datasheet_en.html) und
  [C6](https://documentation.espressif.com/esp32-c6_datasheet_en.html) nicht haben. Der Build läuft trotzdem durch, das würde also vermutlich erst zur Laufzeit
  scheitern.
- **Pins:** Die Pin-Bereiche in Kconfig und die Prüfung des Reset-Pins sind für den klassischen ESP32 geschrieben.
- **Werkzeuge:** Das Setup-Werkzeug sucht einen CH9102-USB-Seriell-Chip, und die CI baut nur für `esp32`.
- **Abnahme:** Die [Hardware-Prüfungen](/de/dokumentation/esp32-hardware-acceptance/) wiederholen, besonders die RAM-Nutzung mit TLS und die gesamte Gruppe H. Die
  RAM-Angaben in dieser Dokumentation gelten nur für den klassischen ESP32.

Braucht ein Chipwechsel auch ein größeres Journal, das vor der Auslieferung von Einheiten entscheiden. Ein größeres Journal bedeutet weniger Flash-Löschvorgänge,
braucht aber ein neues Flash-Layout, das sich nicht Over-the-Air ändern lässt.

## Eine Änderung gegen ein lokales Backend ausführen

Ein Development-Build kann über unverschlüsseltes `mqtt://` mit dem Mosquitto des lokalen Stacks sprechen. Dieser Weg lief von diesen Seiten aus noch nicht an einem
Prüfaufbau.

1. Das Backend starten, wie unter [Loslegen](/de/dokumentation/getting-started/#backend). `docker compose` veröffentlicht den Broker auf Port 1883.
2. Einen [Development-Build](/de/dokumentation/esp32/flashing/#build-wählen) bauen und flashen.
3. Einen Schrank anlegen und ein Token ausstellen, wie unter
   [Inbetriebnahme](/de/dokumentation/esp32/commissioning/#admin-panel-vorbereiten).
4. `python scripts/commission.py --usb` ausführen. Als Broker `mqtt://<LAN-Adresse des Rechners>:1883` verwenden, als Bootstrap-Login
   `MQTT_PROVISIONING_USERNAME` und `MQTT_PROVISIONING_PASSWORD` aus `locker-backend/.env`. Die Einheit muss diese Adresse erreichen, Port 1883 also in der
   Firewall freigeben.
5. Mit `idf.py -p <port> monitor` beobachten. Der Schrank sollte im Admin-Panel online gehen, und ein Öffnen aus dem Panel sollte das Schloss freigeben.

Um den Broker-Weg ohne Panel-Oberfläche zu testen, gibt es `scripts/broker_check.py`, beschrieben unter
[Hardware-Abnahme](/de/dokumentation/esp32-hardware-acceptance/#gruppe-c-netzwerk-mqtt-und-enrollment).

## Die Backend-Seite testen

Updates und Token-Ablauf liegen auch in `locker-backend/`. In diesem Ordner:

```bash
composer test:filter Firmware
```

`composer test` verwenden, nicht `composer test:parallel`. Parallele Worker teilen sich die `ol-test-*`-Cache-Pfade in `phpunit.xml`, ein paralleler Lauf schlägt
also fehl.

## Einen Vertrag ändern

Eine Änderung an einem MQTT-Topic oder einer Nutzlast, am Panel-Protokoll oder an einer Sicherheits- oder Flash-Layout-Entscheidung braucht
[wie üblich](/de/dokumentation/contributing/) ein ADR. Die Schemata in `docs/asyncapi/` werden mit `locker-client/` geteilt, also gemeinsam ändern. Das Flash-Layout lässt
sich bei einer Einheit im Feld nicht ändern.
