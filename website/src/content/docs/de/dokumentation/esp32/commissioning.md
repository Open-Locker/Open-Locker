---
title: Inbetriebnahme
description: Einheit mit dem WLAN verbinden und beim Backend registrieren.
sidebar:
  order: 6
---

Bei der Inbetriebnahme bekommt eine Einheit ihre WLAN-Einstellungen, die Broker-Adresse und ein einmaliges Token.
Danach registriert sie sich selbst und erscheint im Admin-Panel als online. Das Werkzeug läuft einmal pro Einheit
auf einem Laptop.

:::caution[Noch nicht bestanden]
Die Inbetriebnahme über das Setup-WLAN lief einmal auf einem Development-Build. Die Hardwareprüfungen der Gruppe F
sind nicht bestanden, und Zugangsdaten liegen unverschlüsselt auf dem Chip. Das also nur an einem beaufsichtigten
Prüfaufbau durchführen.
:::

## Vorbereitung

- Eine geflashte Einheit. Für das Setup-WLAN braucht sie außerdem ihre
  [Setup-Secrets und das Etikett](/de/dokumentation/esp32/flashing/#setup-secrets-schreiben).
- Ein Admin-Konto im Backend mit der Berechtigung `lockerbank.configure`.
- Die Abhängigkeiten des Werkzeugs, einmalig aus `locker-firmware/` installiert:

  ```bash
  pip install -r requirements.txt
  ```

- Von der Administration des Backends:
  - Die Broker-URL, zum Beispiel `mqtts://mqtt.example.com:8883`. Das Zertifikat muss auf eine öffentliche CA
    zurückgehen. Eine unverschlüsselte `mqtt://`-URL braucht einen
    [Development-Build](/de/dokumentation/esp32/flashing/#build-wählen).
  - Bootstrap-Benutzer und -Passwort: `MQTT_PROVISIONING_USERNAME` und `MQTT_PROVISIONING_PASSWORD` des Backends.

## Admin-Panel vorbereiten

Von diesen Schritten gibt es noch keine Screenshots. Die Bezeichnungen stammen aus dem Quellcode des Panels.

1. Unter **Schließfachschränke** einen Schrank anlegen. **Hardware-Adapter** auf **RS485-Schließfachboard** setzen und
   **Polarität der Schlossrückmeldung** für das Bench-Panel auf **Aktives Signal bedeutet: Tür schließt**.
2. Die Fächer hinzufügen. Für jedes **Nummer**, **Slave-ID** (die Board-Adresse des Panels, `1`) und **Adresse**
   (ab 0, Kanal 1 ist also `0`) setzen. Die Einheit nimmt eine Board-Adresse pro Schrank an.
3. **Bereitstellungstoken ausstellen** wählen und mit **Token ausstellen** bestätigen.
4. Das Token aus dem Dialog kopieren. Es gilt **24 Stunden**, funktioniert **einmal** und wird nicht erneut angezeigt.
   Ist es verloren, liefert **Provisionierung neu starten** ein neues.

## Über das Setup-WLAN (empfohlen)

Eine Einheit ohne Einstellungen öffnet ihr Setup-Netzwerk bei jedem Einschalten für 10 Minuten. Name und Passwort
stehen auf ihrem Etikett. Das Werkzeug stellt seine Fragen, solange der Laptop noch im normalen Netzwerk ist, damit
sich der Broker vor dem Wechsel prüfen lässt.

1. Falls noch nicht geschehen, die Firmware in diesem Checkout bauen. Das Werkzeug nutzt das `esp_prov` aus dem Ordner
   `managed_components/` des Builds, Werkzeug und Firmware passen also immer zusammen. `esp_prov` braucht außerdem zwei
   weitere Python-Pakete:

   ```bash
   pip install cryptography protobuf
   ```

2. Das Werkzeug starten:

   ```bash
   python scripts/commission.py --softap
   ```

3. Das **Setup-Passwort** vom Etikett eingeben.
4. **WLAN-Name** und **Passwort** des Netzwerks eingeben, das die Einheit nutzen soll. Für ein offenes Netz das Passwort
   leer lassen. Dann **Broker-URL**, **Bootstrap-Benutzer** und **-Passwort** sowie das **einmalige Bereitstellungstoken**
   eingeben.

   Secrets werden ohne Anzeige gelesen, und nichts Geheimes landet auf der Festplatte. WLAN-Name und Broker-URL bleiben
   in `~/.openlocker.json` als Vorgabe für den nächsten Lauf.

5. Die Zusammenfassung prüfen und bestätigen. Meldet das Werkzeug vom Laptop aus `Nothing answers at ...`, kann der
   Broker aus dem Netz der Einheit trotzdem erreichbar sein, es lässt sich also fortfahren.
6. Die Einheit aus- und einschalten, falls sie schon länger als 10 Minuten läuft. Bei
   `Join the unit's setup Wi-Fi now` dem Netzwerk beitreten, das auf dem Etikett steht. Das Werkzeug wartet bis zu 3
   Minuten auf die Einheit.
7. Es erscheint `Settings saved. The device now joins the Wi-Fi and enrolls.` Das Setup-Netzwerk schließt sich, und der
   Laptop fällt heraus.

Die Einheit meldet dem Werkzeug immer nur „gespeichert“. Ob sie dem WLAN beigetreten ist und sich registriert hat, zeigt
das Admin-Panel, wie [unten](#prüfen-ob-es-funktioniert-hat) beschrieben.

## Über USB (Release- und Development-Builds)

Das Board einstecken. Ein Production-Build lehnt das ab.

```bash
python scripts/commission.py --usb
```

Das Werkzeug findet ein Board mit CH9102-USB-Seriell-Chip. Bei einem anderen Chip oder wenn es keines findet, den Port
angeben: `--usb COM3` oder `--usb /dev/ttyUSB0`. Vorher jeden seriellen Monitor schließen, sonst ist der Port belegt.

Es stellt dieselben Fragen, außer dem Setup-Passwort. Bei einer registrierten Einheit fragt es nur nach WLAN-Name,
Passwort und Broker und behält Identität, Zuordnung und Journal.

Es erscheint `Settings saved. The device restarts, joins the network and connects to the broker.`

## Prüfen, ob es funktioniert hat

Beim Schließfachschrank im Admin-Panel:

- **Status des Bereitstellungstokens** zeigt **Verbraucht**, und **Bereitgestellt am** enthält eine Zeit.
- **Status** in der Liste zeigt online.
- **Konfigurationsstatus** zeigt **Sauber (vom Client bestätigt)**: Das Backend hat die Zuordnung gesendet, und die
  Einheit hat sie bestätigt.

Dann den [Schrank End-to-End prüfen](/de/dokumentation/esp32/verify/).

## Das Token gilt einmal

Die Einheit speichert „Registrierung ausstehend“, bevor sie das Token sendet. Sie sendet dasselbe Token also nie zweimal,
was auch immer danach geschieht.

- **Die WLAN-Einstellungen waren falsch, das Token wurde nie gesendet:** Die Einheit versucht weiter, das WLAN zu erreichen.
  [Zurücksetzen](/de/dokumentation/esp32/maintenance/#einheit-zurücksetzen) und erneut in Betrieb nehmen. Dasselbe Token
  funktioniert innerhalb seiner 24 Stunden weiter.
- **Die Antwort ging verloren, oder das Backend hat das Token abgelehnt:** Die Einheit versucht es nicht erneut. Nach einem
  Neustart wartet sie weiter auf eine späte Antwort und registriert sich, falls eine kommt. Andernfalls lehnt sie neue
  Einstellungen ab, bis sie zurückgesetzt wird. **Provisionierung neu starten** im Panel wählen, die Einheit zurücksetzen und
  mit dem neuen Token in Betrieb nehmen.
- **Das Werkzeug verlor nach dem Senden die Verbindung:** Es wird nichts erneut gesendet, weil die Einstellungen vielleicht
  gespeichert wurden. Vor einem neuen Versuch das Admin-Panel prüfen oder `status` auf der Konsole ausführen.

## Inbetriebnahmeprotokoll

Ein Protokoll pro Einheit führen. Nie die Secrets vom Etikett oder das Token eintragen. Sie sind absichtlich die einzigen
Kopien.

| Feld | Wert |
| --- | --- |
| Schließfachschrank | |
| WLAN-Name vom Etikett (nicht geheim) | |
| Ort des Etiketts | |
| Firmware-Version und Image-SHA-256 | |
| Verwendeter Signaturschlüssel (Bench oder Release) | |
| Datum | |
| Bedienperson | |

## Wenn es nicht funktioniert hat

Siehe [Fehlerbehebung](/de/dokumentation/esp32/troubleshooting/#symptom-ursache-lösung).

## Weiter

[End-to-End prüfen](/de/dokumentation/esp32/verify/).
