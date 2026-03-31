# Stückliste

Deutsche Version. Die englische Originalfassung ist unter
[`docs/Bill-of-Materials.md`](Bill-of-Materials.md) verfügbar.

Dieses Dokument beschreibt eine vorgeschlagene Stückliste für einen
Open-Locker-Testschrank.

Es basiert auf der aktuell eingesetzten Raspberry-Pi-Variante und dokumentiert
zunächst die schrankunabhängigen elektrischen Komponenten. Eine ESP-basierte
Variante ist geplant, aber noch nicht aufgebaut oder validiert.

## Geltungsbereich

Diese Stückliste konzentriert sich auf:

- Controller- und Kommunikationshardware
- Relais- und Schlossansteuerung
- Verkabelung, Steckverbinder und Stromverteilung
- optionale Teile, die den Aufbau und die Erweiterung vereinfachen

Diese Stückliste deckt bewusst keine schrankabhängige mechanische Integration
ab, zum Beispiel:

- wie Schlösser in einen Holzschrank eingebaut werden
- wie Schlösser in einen Metallschrank eingebaut werden
- Bohrungen, Halterungen, Blechbearbeitung oder Verstärkungen

Diese Details hängen stark vom jeweiligen Schranktyp ab und sollten separat
dokumentiert werden, sobald es ein wiederholbares Schrankdesign gibt.

## Allgemeine Hinweise

- Die Lieferantenlinks sind nur Beispiele. Gleichwertige Teile anderer Anbieter
  sind in Ordnung, sofern ein Teil nicht ausdrücklich als erforderlich markiert
  ist.
- Wir haben derzeit keine Affiliate-Beziehungen zu den in diesem Dokument
  genannten Anbietern.
- Für die Beschaffung verwenden wir möglichst neutrale technische
  Bezeichnungen. Marketplace-Titel enthalten oft Marketing-Sprache, die für
  die Dokumentation wenig hilfreich ist.
- Das Modbus-Relaisboard muss das unten genannte Waveshare-Board sein, weil die
  aktuelle Implementierung auf verifiziertem Waveshare-spezifischem Flash- und
  Digital-Input-Verhalten basiert. Siehe
  [`docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`](adr/0004-waveshare-hardware-flash-and-supported-boards.md).
- Kabellängen hängen von der Schrankgröße und der Kabelführung ab.
- Die Dimensionierung des Netzteils hängt von der Anzahl der Fächer,
  Schlösser und Relaisboards im finalen Schrank ab.
- Diese Stückliste ist ein dokumentiertes Praxisbeispiel für den bisherigen
  Aufbau. Sie ist keine Garantie für einen bestimmten Lieferanten, Hersteller
  oder Teile-Stand.

## Build-Profile

### Raspberry-Pi-Variante

Dies ist die derzeit eingesetzte und validierte Build-Variante.

### ESP-Variante

Diese Build-Variante ist geplant, aber noch nicht aufgebaut oder validiert.

Auf Schrankseite bleiben voraussichtlich weitgehend gleich:

- Schloss-Hardware
- Relais-Hardware
- Verkabelung
- Steckverbinder
- 12-V-Stromverteilung

Der Controller-Stack wird sich voraussichtlich ändern. Deshalb ist die
controller-spezifische Stückliste für die ESP-Variante noch `TBD`.

## Empfohlenes Connection Board

Wir empfehlen das Open-Locker-Connection-Board aus dem Repository, um die
Verkabelung im Schrank einfacher zu machen.

Empfohlene Variante:

- [`hardware/connection-board-cut-out_3_5`](../hardware/connection-board-cut-out_3_5)

Das ist die 3,5-mm-Klemmenvariante. Sie ist einfacher zu verdrahten und zu
warten als direkte Punkt-zu-Punkt-Verkabelung.

Pro Modbus-Board beziehungsweise Connection Board werden in diesem Design
typischerweise verwendet:

- 10x 2-polige Leiterplatten-Schraubklemmen
- 13x 4-polige Leiterplatten-Schraubklemmen

Beispiel für einen passenden Steckverbinder-Satz:

- 3,5-mm-Leiterplatten-Schraubklemmen
  [Beispiellink](https://de.aliexpress.com/item/1005008051970362.html)

Hinweis zur Leiterplattenfertigung:

- Wir haben unsere PCBs bei [JLCPCB](https://jlcpcb.com/) bestellt.

## Gemeinsame Komponenten

Diese Teile gelten für beide Build-Profile, sofern nicht anders angegeben.

| Teil | Typische Menge | Hinweise | Beispiellink |
| --- | --- | --- | --- |
| 8-Kanal-Modbus-RTU-Relaisboard mit Digitaleingängen | 1 pro bis zu 8 Fächern | Erforderliches Teil. Verwendet das Waveshare `Modbus RTU Relay (D)` Board, weil die aktuelle Software dessen Flash- und Digital-Input-Funktionen nutzt. Pro Fach wird ein Relaisausgang und ein Digitaleingang benötigt. | [Waveshare-Relaisboard](https://www.amazon.de/dp/B0CRKPYVSN) |
| 12-V-Schrankschloss mit Rückmeldekontakt | 1 pro Fach | Wenn möglich, ein Schloss mit integriertem Status- oder Erkennungsschalter verwenden. Die konkrete Montage hängt vom Schrankmaterial und Türdesign ab. | [Beispiel A](https://www.amazon.de/dp/B07B9WMKG2), [Beispiel B](https://www.amazon.de/dp/B071WBDFZR) |
| Gleichrichterdiode `1N4007` | 1 pro Schloss | Empfohlen als Freilaufdiode parallel zu induktiven 12-V-Lasten wie Schrankschlössern. | [Beispiel-Dioden](https://www.amazon.de/Doradus-100pcs-1n4007-in4007-Gleichrichterdiode/dp/B01D2UEBSQ?source=ps-sl-shoppingads-lpcontext&ref_=fplfs&psc=1&smid=A32CPP7DOUV3) |
| 12-V-DC-Netzteil | 1 pro Schrank | Das Netzteil für die maximal gleichzeitig aktiven Schlösser plus Controller- und Relaisboard-Reserve dimensionieren. | [Beispiel-Netzteil](https://www.amazon.de/dp/B07GFFG1BQ) |
| Interne Schrankverkabelung | nach Bedarf | Die Kabellänge passend zu Schrankgeometrie und Kabelführung wählen. | [Beispielkabel](https://www.amazon.de/dp/B0BHSVC7HP) |
| Aderendhülsen, 0,34 mm2 | nach Bedarf | Empfohlen für saubere und zuverlässige Anschlüsse an Schraubklemmen. | [Beispiel-Aderendhülsen](https://www.amazon.de/dp/B0DJ759X65) |
| 2-polige Steckverbinder, 2,5 mm Raster | optional | Optional. Kann auch durch direkt gelötete Verbindungen ersetzt werden. | [Beispiel-Steckverbinder](https://www.amazon.de/dp/B07QM13SRX) |
| Einbau-DC-Buchse, 5,5 x 2,1 mm | 1 pro Schrank | Sinnvoll, um die externe 12-V-Versorgung von aussen in den Schrank zu führen. | [Beispiel-DC-Buchse](https://www.amazon.de/dp/B0F24DFZHF) |
| 4-poliger GX16-Steckverbindersatz | optional | Sinnvoll, wenn mehrere Schränke elektrisch miteinander verbunden werden sollen. | [Beispiel-GX16-Steckverbinder](https://www.amazon.de/-/en/Aiqeer-Aviation-Thread-Connector-Female/dp/B09WXZNKXN/) |
| Connection Board, 3,5-mm-Klemmenvariante | nach Bedarf | Empfohlen, um die Verkabelung im Schrank zu vereinfachen. Verwendet das Design aus `hardware/connection-board-cut-out_3_5`. | [Repository-Design](../hardware/connection-board-cut-out_3_5) |
| 3,5-mm-Leiterplatten-Schraubklemmen für das Connection Board | 1 Satz pro Connection Board | Typische Bestückung: 10x 2-polig und 13x 4-polig pro Board. | [Beispiel-Klemmensatz](https://de.aliexpress.com/item/1005008051970362.html) |
| Kurze Kabelstrecken zwischen Connection Board und Relaisboard | nach Bedarf | Lautsprecherkabel oder vergleichbare Litze sind für kurze Strecken geeignet. Für die jeweilige Strecke Kabel für bis zu 12 V / 2 A auslegen. | Kein fester Anbieter |

## Raspberry-Pi-spezifische Komponenten

Diese Teile werden nur für die aktuell genutzte Raspberry-Pi-Variante
benötigt.

| Teil | Typische Menge | Hinweise | Beispiellink |
| --- | --- | --- | --- |
| Raspberry Pi 4 oder 5 | 1 pro Schrank-Controller | Der aktuelle Aufbau verwendet einen Raspberry Pi 4 mit 4 GB RAM. | Kein fester Anbieter |
| USB-zu-RS485-Adapter | 1 | Verbindet den Raspberry Pi mit dem Modbus-RTU-Relaisboard. | [Waveshare USB-zu-RS485-Adapter](https://www.amazon.de/dp/B0B87D9LNC) |
| 12-V-zu-5-V-DC-DC-Wandler | 1 | Versorgt den Raspberry Pi aus der 12-V-Stromversorgung des Schranks. | [Beispiel-DC-DC-Wandler](https://www.amazon.de/dp/B09PFV3SWN) |

## Status der ESP-Variante

Die ESP-basierte Variante ist noch nicht finalisiert.

Derzeit können folgende Punkte als stabil betrachtet werden:

- Schloss-Hardware
- Relaisboard-Anforderung
- Empfehlung für das Connection Board
- grundsätzlicher Ansatz für die interne Verkabelung
- grundsätzliche 12-V-Stromverteilung

Für die folgenden Punkte ist noch eine eigene Design- und Validierungsphase
nötig:

- Auswahl des ESP-Moduls
- Controller-Träger- oder Interface-Board
- RS485-Interface-Strategie
- Stromversorgung und Schutzbeschaltung für den Controller
- Service- und Update-Workflow

Bis diese Punkte abgeschlossen sind, dokumentiert dieses Dokument nur eine
validierte Stückliste für die Raspberry-Pi-Variante.

## Nicht abgedeckte schrankabhängige Teile

Die folgenden Themen sind bewusst nicht Teil dieser Stückliste, weil sie vom
konkreten Schrank abhängen:

- Schloss-Halterungen und Montageplatten
- Bohrschablonen
- Türverstärkungen
- Montagematerial für Holzschränke
- Montagematerial für Metallschränke
- Gehäuseausschnitte und Nachbearbeitung

Diese Punkte sollten in einer schrank-spezifischen Aufbauanleitung dokumentiert
werden, sobald ein wiederholbares Schrankdesign existiert.

## Haftungsausschluss

Diese Stückliste dient als praktische Referenz für das Open-Locker-Projekt.
Teile und Lieferanten können sich im Laufe der Zeit ändern. Das Projekt
übernimmt keine Gewährleistung oder Lieferantenzusagen für die gelisteten
Teile.

## Feedback

Wenn ihr einen Open-Locker-Schrank auf Basis dieser Stückliste baut, freuen
wir uns über Feedback.

Hilfreiches Feedback wäre zum Beispiel:

- welche Teile gut funktioniert haben
- welche Teile schwer zu beschaffen waren
- kompatible Alternativteile oder andere Bezugsquellen
- schrankspezifische Erfahrungen aus dem Aufbau
- Korrekturen bei Mengen, elektrischen Werten oder Verdrahtungsannahmen

Rückmeldungen aus der Community helfen uns dabei, diese Stückliste zu
verbessern und künftige Builds leichter reproduzierbar zu machen.
