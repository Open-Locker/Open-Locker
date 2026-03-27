# Stueckliste

Deutsche Version. Die englische Originalfassung ist unter
[`docs/Bill-of-Materials.md`](Bill-of-Materials.md) verfuegbar.

Dieses Dokument beschreibt eine vorgeschlagene Stueckliste fuer einen
Open-Locker-Testschrank.

Es basiert auf der aktuell eingesetzten Raspberry-Pi-Variante und dokumentiert
zunaechst die schrankunabhaengigen elektrischen Komponenten. Eine ESP-basierte
Variante ist geplant, aber noch nicht aufgebaut oder validiert.

## Geltungsbereich

Diese Stueckliste konzentriert sich auf:

- Controller- und Kommunikationshardware
- Relais- und Schlossansteuerung
- Verkabelung, Steckverbinder und Stromverteilung
- optionale Teile, die den Aufbau und die Erweiterung vereinfachen

Diese Stueckliste deckt bewusst keine schrankabhaengige mechanische Integration
ab, zum Beispiel:

- wie Schloesser in einen Holzschrank eingebaut werden
- wie Schloesser in einen Metallschrank eingebaut werden
- Bohrungen, Halterungen, Blechbearbeitung oder Verstaerkungen

Diese Details haengen stark vom jeweiligen Schranktyp ab und sollten separat
dokumentiert werden, sobald es ein wiederholbares Schrankdesign gibt.

## Allgemeine Hinweise

- Die Lieferantenlinks sind nur Beispiele. Gleichwertige Teile anderer Anbieter
  sind in Ordnung, sofern ein Teil nicht ausdruecklich als erforderlich markiert
  ist.
- Wir haben derzeit keine Affiliate-Beziehungen zu den in diesem Dokument
  genannten Anbietern.
- Fuer die Beschaffung verwenden wir moeglichst neutrale technische
  Bezeichnungen. Marketplace-Titel enthalten oft Marketing-Sprache, die fuer
  die Dokumentation wenig hilfreich ist.
- Das Modbus-Relaisboard muss das unten genannte Waveshare-Board sein, weil die
  aktuelle Implementierung auf verifiziertem Waveshare-spezifischem Flash- und
  Digital-Input-Verhalten basiert. Siehe
  [`docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`](adr/0004-waveshare-hardware-flash-and-supported-boards.md).
- Kabellaengen haengen von der Schrankgroesse und der Kabelfuehrung ab.
- Die Dimensionierung des Netzteils haengt von der Anzahl der Faecher,
  Schloesser und Relaisboards im finalen Schrank ab.
- Diese Stueckliste ist ein dokumentiertes Praxisbeispiel fuer den bisherigen
  Aufbau. Sie ist keine Garantie fuer einen bestimmten Lieferanten, Hersteller
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

Der Controller-Stack wird sich voraussichtlich aendern. Deshalb ist die
controller-spezifische Stueckliste fuer die ESP-Variante noch `TBD`.

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

Beispiel fuer einen passenden Steckverbinder-Satz:

- 3,5-mm-Leiterplatten-Schraubklemmen
  [Beispiellink](https://de.aliexpress.com/item/1005008051970362.html)

Hinweis zur Leiterplattenfertigung:

- Wir haben unsere PCBs bei [JLCPCB](https://jlcpcb.com/) bestellt.

## Gemeinsame Komponenten

Diese Teile gelten fuer beide Build-Profile, sofern nicht anders angegeben.

| Teil | Typische Menge | Hinweise | Beispiellink |
| --- | --- | --- | --- |
| 8-Kanal-Modbus-RTU-Relaisboard mit Digitaleingaengen | 1 pro bis zu 8 Faechern | Erforderliches Teil. Verwendet das Waveshare `Modbus RTU Relay (D)` Board, weil die aktuelle Software dessen Flash- und Digital-Input-Funktionen nutzt. Pro Fach wird ein Relaisausgang und ein Digitaleingang benoetigt. | [Waveshare-Relaisboard](https://www.amazon.de/dp/B0CRKPYVSN) |
| 12-V-Schrankschloss mit Rueckmeldekontakt | 1 pro Fach | Wenn moeglich, ein Schloss mit integriertem Status- oder Erkennungsschalter verwenden. Die konkrete Montage haengt vom Schrankmaterial und Tuerdesign ab. | [Beispiel A](https://www.amazon.de/dp/B07B9WMKG2), [Beispiel B](https://www.amazon.de/dp/B071WBDFZR) |
| 12-V-DC-Netzteil | 1 pro Schrank | Das Netzteil fuer die maximal gleichzeitig aktiven Schloesser plus Controller- und Relaisboard-Reserve dimensionieren. | [Beispiel-Netzteil](https://www.amazon.de/dp/B07GFFG1BQ) |
| Interne Schrankverkabelung | nach Bedarf | Die Kabellaenge passend zu Schrankgeometrie und Kabelfuehrung waehlen. | [Beispielkabel](https://www.amazon.de/dp/B0BHSVC7HP) |
| Aderendhuelsen, 0,34 mm2 | nach Bedarf | Empfohlen fuer saubere und zuverlaessige Anschluesse an Schraubklemmen. | [Beispiel-Aderendhuelsen](https://www.amazon.de/dp/B0DJ759X65) |
| 2-polige Steckverbinder, 2,5 mm Raster | optional | Optional. Kann auch durch direkt geloetete Verbindungen ersetzt werden. | [Beispiel-Steckverbinder](https://www.amazon.de/dp/B07QM13SRX) |
| Einbau-DC-Buchse, 5,5 x 2,1 mm | 1 pro Schrank | Sinnvoll, um die externe 12-V-Versorgung von aussen in den Schrank zu fuehren. | [Beispiel-DC-Buchse](https://www.amazon.de/dp/B0F24DFZHF) |
| 4-poliger GX16-Steckverbindersatz | optional | Sinnvoll, wenn mehrere Schraenke elektrisch miteinander verbunden werden sollen. | [Beispiel-GX16-Steckverbinder](https://www.amazon.de/-/en/Aiqeer-Aviation-Thread-Connector-Female/dp/B09WXZNKXN/) |
| Connection Board, 3,5-mm-Klemmenvariante | nach Bedarf | Empfohlen, um die Verkabelung im Schrank zu vereinfachen. Verwendet das Design aus `hardware/connection-board-cut-out_3_5`. | [Repository-Design](../hardware/connection-board-cut-out_3_5) |
| 3,5-mm-Leiterplatten-Schraubklemmen fuer das Connection Board | 1 Satz pro Connection Board | Typische Bestueckung: 10x 2-polig und 13x 4-polig pro Board. | [Beispiel-Klemmensatz](https://de.aliexpress.com/item/1005008051970362.html) |
| Kurze Kabelstrecken zwischen Connection Board und Relaisboard | nach Bedarf | Lautsprecherkabel oder vergleichbare Litze sind fuer kurze Strecken geeignet. Fuer die jeweilige Strecke Kabel fuer bis zu 12 V / 2 A auslegen. | Kein fester Anbieter |

## Raspberry-Pi-spezifische Komponenten

Diese Teile werden nur fuer die aktuell genutzte Raspberry-Pi-Variante
benoetigt.

| Teil | Typische Menge | Hinweise | Beispiellink |
| --- | --- | --- | --- |
| Raspberry Pi 4 oder 5 | 1 pro Schrank-Controller | Der aktuelle Aufbau verwendet einen Raspberry Pi 4 mit 4 GB RAM. | Kein fester Anbieter |
| USB-zu-RS485-Adapter | 1 | Verbindet den Raspberry Pi mit dem Modbus-RTU-Relaisboard. | [Waveshare USB-zu-RS485-Adapter](https://www.amazon.de/dp/B0B87D9LNC) |
| 12-V-zu-5-V-DC-DC-Wandler | 1 | Versorgt den Raspberry Pi aus der 12-V-Stromversorgung des Schranks. | [Beispiel-DC-DC-Wandler](https://www.amazon.de/dp/B09PFV3SWN) |

## Status der ESP-Variante

Die ESP-basierte Variante ist noch nicht finalisiert.

Derzeit koennen folgende Punkte als stabil betrachtet werden:

- Schloss-Hardware
- Relaisboard-Anforderung
- Empfehlung fuer das Connection Board
- grundsaetzlicher Ansatz fuer die interne Verkabelung
- grundsaetzliche 12-V-Stromverteilung

Fuer die folgenden Punkte ist noch eine eigene Design- und Validierungsphase
noetig:

- Auswahl des ESP-Moduls
- Controller-Traeger- oder Interface-Board
- RS485-Interface-Strategie
- Stromversorgung und Schutzbeschaltung fuer den Controller
- Service- und Update-Workflow

Bis diese Punkte abgeschlossen sind, dokumentiert dieses Dokument nur eine
validierte Stueckliste fuer die Raspberry-Pi-Variante.

## Nicht abgedeckte schrankabhaengige Teile

Die folgenden Themen sind bewusst nicht Teil dieser Stueckliste, weil sie vom
konkreten Schrank abhaengen:

- Schloss-Halterungen und Montageplatten
- Bohrschablonen
- Tuerverstaerkungen
- Montagematerial fuer Holzschraenke
- Montagematerial fuer Metallschraenke
- Gehaeuseausschnitte und Nachbearbeitung

Diese Punkte sollten in einer schrank-spezifischen Aufbauanleitung dokumentiert
werden, sobald ein wiederholbares Schrankdesign existiert.

## Haftungsausschluss

Diese Stueckliste dient als praktische Referenz fuer das Open-Locker-Projekt.
Teile und Lieferanten koennen sich im Laufe der Zeit aendern. Das Projekt
uebernimmt keine Gewaehrleistung oder Lieferantenzusagen fuer die gelisteten
Teile.

## Feedback

Wenn ihr einen Open-Locker-Schrank auf Basis dieser Stueckliste baut, freuen
wir uns ueber Feedback.

Hilfreiches Feedback waere zum Beispiel:

- welche Teile gut funktioniert haben
- welche Teile schwer zu beschaffen waren
- kompatible Alternativteile oder andere Bezugsquellen
- schrankspezifische Erfahrungen aus dem Aufbau
- Korrekturen bei Mengen, elektrischen Werten oder Verdrahtungsannahmen

Rueckmeldungen aus der Community helfen uns dabei, diese Stueckliste zu
verbessern und kuenftige Builds leichter reproduzierbar zu machen.
