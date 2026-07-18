---
title: Überblick
description: Was Open Locker ist, für wen es gedacht ist und aus welchen Komponenten das System besteht.
sidebar:
  order: 1
---

Open Locker ist ein Open-Source-Projekt für öffentliche Schließfachsysteme:
Software und Hardware-Baupläne, um digitale Schließfächer selbst zu bauen, zu
teilen und zu verwalten — zum Lagern und Verleihen von Gegenständen wie
Laptops, Werkzeug oder VR-Headsets.

## Für wen ist Open Locker?

- **Kommunen und Smart-City-Projekte**, die Verleihstationen betreiben wollen
- **Vereine, Communities und Bildungseinrichtungen**, für die kommerzielle
  Smart-Locker-Lösungen zu teuer sind
- **Maker und Entwickler:innen**, die bestehende Schränke umrüsten möchten

## Die Komponenten

| Komponente | Beschreibung |
| --- | --- |
| **Backend** | Laravel-API mit Filament-Admin-Panel — Quelle der Wahrheit für Daten, Berechtigungen und Kommandos |
| **Mobile App** | React-Native-App (Expo) für Endnutzer:innen: Fächer öffnen, Gegenstände ausleihen und zurückgeben |
| **Locker Client** | TypeScript-Dienst auf einem Raspberry Pi am Schrank: empfängt Kommandos per MQTT und steuert die Schlösser per Modbus |
| **Hardware** | Baupläne und Stückliste für die Elektronik (Relais-Boards, Schlösser, Verkabelung) |

Wie die Teile zusammenspielen, beschreibt die [Architektur](/dokumentation/architecture/).

## Erste Schritte

- [Loslegen](/dokumentation/getting-started/) — lokale Entwicklungsumgebung aufsetzen
- [Betrieb](/dokumentation/operations/) — Produktions-Deployment und Hosting
- [Mitmachen](/dokumentation/contributing/) — zum Projekt beitragen

## Community

Wöchentliches Treffen auf [Discord](https://discord.gg/rZ74RYKN3H) jeden
Dienstag um 19:30 Uhr. Quellcode und Issues auf
[GitHub](https://github.com/Open-Locker/Open-Locker).
