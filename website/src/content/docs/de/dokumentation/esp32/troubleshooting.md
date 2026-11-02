---
title: Fehlerbehebung
description: Symptome, Ursachen und Lösungen für ESP32-Locker-Controller.
sidebar:
  order: 8
---

Das Beobachtete in den Tabellen unten suchen. Zitierter Text ist die genaue Meldung von Werkzeug, Konsole oder Log. Zum Lesen des Logs
`idf.py -p COM3 monitor` ausführen und die Einheit neu starten.

## Symptom, Ursache, Lösung

### Bus und Schlösser

| Beobachtung | Ursache | Lösung |
| --- | --- | --- |
| `error query no_valid_reply` oder `bus=disconnected` | Panel ohne Strom, falsche Verkabelung oder falsche Adresse | Panelversorgung, A an A und B an B, RXD und TXD sowie die DIP-Adresse prüfen |
| `bus=unreachable` | Wiederholte Fehler haben eine Abkühlphase ausgelöst, in der nichts geschrieben wird. Nach 5 Fehlern dauert sie 60 s | Ursache beheben und warten. Kein Neustart nötig |
| `query` zeigt jede Tür als `o`, und `unlock` wird nicht bestätigt | Der Bus gibt Anfragen nur als Echo zurück: ein Verkabelungsfehler | Verkabelung vom Wandler zum Panel prüfen |
| `error unlock outcome_unknown` | Innerhalb von 1500 ms kam keine verwertbare Antwort. Das Schloss hat sich vielleicht bewegt | Schloss ansehen. Nie annehmen, dass es zu blieb. Der Bus bleibt danach 3 s still |
| `error unlock not_sent` | Die Anfrage erreichte die Leitung nicht, zum Beispiel während einer Abkühlphase | Ende der Abkühlphase abwarten |
| `error unlock refused` | Das Panel antwortete mit seinem Fehlerstatus | Schloss und Kanal prüfen |
| Eine Antwort mit `RUNTIME_CONFIG_NOT_APPLIED` | Noch keine Zuordnung | **Konfiguration an Client senden** im Panel nutzen oder am Prüfaufbau `apply_config` senden |

### Inbetriebnahme und Registrierung

| Beobachtung | Ursache | Lösung |
| --- | --- | --- |
| Das Setup-Netzwerk erscheint nicht | Es existiert nur 10 Minuten nach dem Einschalten und nur bei einer Einheit ohne Einstellungen und mit Setup-Secrets. Log: `no setup secrets stored: the setup network stays closed` | Einheit aus- und einschalten oder [Secrets schreiben](/de/dokumentation/esp32/flashing/#setup-secrets-schreiben) |
| `No reply from the device. Join its setup Wi-Fi ...` | Der Laptop ist nicht im Setup-Netzwerk, oder die 10 Minuten sind vorbei | Einheit aus- und einschalten, dem Netzwerk beitreten und das Werkzeug erneut starten |
| `The setup password was not accepted.` | Falsches Setup-Passwort. Fünf fehlgeschlagene Sitzungen schließen das Netzwerk bis zum nächsten Neustart | Etikett prüfen |
| `The device refused the settings. Nothing was saved.` | Ein Wert hat die Prüfung nicht bestanden | Werte prüfen und das Werkzeug erneut starten |
| `esp_prov or its dependencies were not found` | Kein Firmware-Build in diesem Checkout oder fehlende Python-Pakete | Firmware bauen, dann `pip install cryptography protobuf` |
| `No board found (no CH9102 USB-serial chip).` | Das Board hat einen anderen Chip oder steckt nicht | Port angeben: `--usb COM3` |
| `An enrollment is pending on this device ...` | Das Token wurde gesendet, und keine Antwort kam. Die Einheit [sendet es nie zweimal](/de/dokumentation/esp32/commissioning/#das-token-gilt-einmal) | **Provisionierung neu starten** wählen, Einheit zurücksetzen und mit dem neuen Token in Betrieb nehmen |
| Log: `the backend refused the provisioning token` | Das Token wurde benutzt, ersetzt oder ist älter als 24 Stunden | Dieselbe Lösung |
| `The broker URL must look like mqtts://host:port` oder `error invalid_settings` bei `mqtt://` | Ein Release- oder Production-Build nimmt nur `mqtts://` an | `mqtts://` oder einen [Development-Build](/de/dokumentation/esp32/flashing/#build-wählen) verwenden |
| `The broker URL points at ..., which is the device itself.` | `localhost` meint die Einheit, nicht den Laptop | Die Netzwerkadresse des Laptops verwenden |
| Log: `refusing a broker URL that is not mqtts://` | Eine von einem Development-Build gespeicherte URL, jetzt auf einem Release-Build | Einheit zurücksetzen und mit `mqtts://` erneut in Betrieb nehmen |

### Netzwerk und Broker

| Beobachtung | Ursache | Lösung |
| --- | --- | --- |
| Log: `the network is up but the time is not set (NTP blocked?); not connecting` | Die Zertifikatsprüfung braucht das Datum. Die Einheit nutzt `pool.ntp.org` und wartet auf die Zeit | NTP in diesem Netz erlauben |
| Log: `transport error: tls=...` | Das Zertifikat des Brokers geht nicht auf eine öffentliche CA zurück, oder sein Name passt nicht. Die Prüfung lässt sich nicht abschalten | Zertifikat einer öffentlichen CA verwenden. Eine private CA muss in den Build aufgenommen werden |
| Log: `the broker refused the connection (code ...)` | Falsche Zugangsdaten, oder die Identität wurde durch einen Provisionierungs-Reset widerrufen | Einheit zurücksetzen und mit einem neuen Token in Betrieb nehmen |
| Log: `the broker refused subscription ... (check its ACL)` | Die Zugriffsregeln des Brokers erlauben das Topic nicht | [Broker-Einrichtung](/de/dokumentation/operations/) prüfen |

### Journal

| Beobachtung | Ursache | Lösung |
| --- | --- | --- |
| Log: `the command journal is not healthy: commands are refused until it is erased` | Ein beschädigter Eintrag, ein fehlgeschlagener Flash-Schreib- oder Löschvorgang oder eine fehlende `journal`-Partition. Ein Stromausfall verursacht das nicht. Bis zur Behebung wird nichts ausgelöst | Partition löschen, siehe unten. Dann die [Einheit zurücksetzen](/de/dokumentation/esp32/maintenance/#einheit-zurücksetzen), **Restart provisioning** wählen und sie erneut in Betrieb nehmen |
| Kommandos werden mit `UNKNOWN_ERROR` abgelehnt | 32 Antworten warten auf den Broker | Broker-Verbindung reparieren. Gespeicherte Antworten werden zuerst zugestellt, das hebt die Grenze auf |

Journal löschen:

```bash
parttool.py --port COM3 --partition-table-offset 0xC000 erase_partition --partition-name journal
```

### Over-the-Air-Updates

| Beobachtung | Ursache | Lösung |
| --- | --- | --- |
| Keine Aktion **Firmware aktualisieren** beim Schrank | Die Einheit hat die Fähigkeit `firmware_update` nicht gemeldet | Heartbeat einer Firmware abwarten, die sie hat |
| Update-Fehler `UPDATE_VERIFY_FAILED` | Fehlerhafte Signatur, anderer Schlüssel oder falsche Version im Image | [Image signieren](/de/dokumentation/esp32/flashing/#image-signieren), mit dem Schlüssel des laufenden Images |
| Update-Fehler `UPDATE_VERSION_REJECTED` | Die Einheit ist von dieser Version zurückgekehrt | Ein neues Release mit der nächsten Patch-Version veröffentlichen |
| Update schlägt mit Grund `timeout` fehl | Kein Ergebnis 30 Minuten nach der Anforderung | Verbindung der Einheit prüfen und erneut versuchen |

Die [Referenz](/de/dokumentation/esp32/reference/#fehlercodes-für-kommandos) nennt alle Codes.

## Boot-Log lesen

Das Boot-Log zeigt, in welchem Modus die Einheit gestartet ist:

| Log-Zeile | Bedeutung |
| --- | --- |
| `no network settings: bench mode, commands come from the console` | Keine Einstellungen gespeichert |
| `setup network open for 600 s` | Das Setup-Netzwerk ist offen |
| `a half-finished reset was found and finished` | Ein Reset wurde unterbrochen, und die Einheit hat ihn abgeschlossen |
| `enrolled; restarting to connect with the issued identity` | Die Registrierung hat funktioniert |
| `DEVELOPMENT BUILD: connecting without TLS, credentials travel in clear` | Ein Development-Build mit `mqtt://`-Broker |

## Hilfe zu einer Einheit

Auf [Discord](https://discord.gg/rZ74RYKN3H) fragen oder ein
[GitHub-Issue](https://github.com/Open-Locker/Open-Locker/issues) eröffnen. Dazu gehören:

- die Ausgabe von `status` und `diagnostics`, die keine Secrets enthält;
- die Firmware-Version und der verwendete Build;
- das Boot-Log, nachdem es auf Privates geprüft wurde.

Nie die Secrets vom Etikett, ein Token oder ein WLAN-Passwort veröffentlichen.
