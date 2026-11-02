---
title: End-to-End prüfen
description: Bestätigen, dass der in Betrieb genommene Schrank aus Admin-Panel und App funktioniert.
sidebar:
  order: 7
---

Diese vier Prüfungen zeigen, dass eine in Betrieb genommene Einheit über das echte Backend funktioniert: Sie meldet sich,
öffnet ein Fach, folgt der Tür und erholt sich von einem Ausfall. Sie sind eine Kurzfassung der Hardwaregruppen C und D, die
noch kein abgezeichnetes Ergebnis haben. Ein Fehlschlag hier kann also ein Firmware-Fehler sein. Bitte
[melden](/de/dokumentation/esp32/troubleshooting/#hilfe-zu-einer-einheit).

## Der Schrank zeigt sich als verbunden

Im Admin-Panel **Schließfachschränke** öffnen. Die Zeile des Schranks sollte zeigen:

| Spalte | Erwartet |
| --- | --- |
| **Status** | online |
| **Hardware** | erreichbar. Steht dort unerreichbar, ist die Einheit online, erreicht aber das Panel nicht |
| **Firmware** | Die von der Einheit gemeldete Version. Ein Build ohne `LC_FIRMWARE_VERSION` meldet `0.0.0-dev` |

## Ein Fach öffnen

:::caution[Das gibt ein Schloss frei]
Am Schrank bleiben und die Tür freihalten.
:::

1. Den Schrank bearbeiten und bei seinen Fächern **Öffnen** für Fach 1 wählen. Das Schloss sollte einmal freigeben.
2. Dasselbe Fach aus der Mobile App mit einem Konto öffnen, das Zugriff darauf hat. Das Schloss sollte noch einmal
   freigeben.

## Türereignisse erreichen die App

1. Die Tür von Hand öffnen und schließen.
2. Admin-Panel und App beobachten. Beide sollten innerhalb von etwa zwei Sekunden folgen.

Jetzt das Fach aus dem Panel öffnen und die Tür zuhalten. Die Firmware meldet die Tür als klemmend, wenn ihr
Erkennungsfenster endet. Dieses Fenster entspricht dem **Heartbeat-Intervall** des Schranks.

## Strom- und Netzausfall

1. Die Einheit ausstecken. Der Schrank sollte offline erscheinen, sobald **Heartbeat-Timeout (Sekunden)** verstrichen ist.
   Standard sind 30 Sekunden. Das Backend wertet die Last-Will-Nachricht des Brokers noch nicht aus.
2. Wieder einstecken. Sie sollte sich ohne Hilfe neu verbinden und wieder online erscheinen. Beim Start sendet sie keine
   Freigabe.
3. Den WLAN-Access-Point für eine Minute ausschalten, dann den Broker. Die Einheit sollte sich jedes Mal selbst neu verbinden.

## Wenn es nicht funktioniert hat

Siehe [Fehlerbehebung](/de/dokumentation/esp32/troubleshooting/#symptom-ursache-lösung).

## Weiter

[Warten und zurücksetzen](/de/dokumentation/esp32/maintenance/).
