---
title: Wiring
description: Connect the ESP32, the RS485 converter, the lock panel and the locks.
sidebar:
  order: 3
---

## Wiring diagram

<!-- One SVG: ESP32 → XY-485 → panel → lock. Bench photos. -->

## ESP32 to RS485 converter

<!-- GPIO 17 → RXD, GPIO 16 → TXD, 3V3 → VCC, GND → GND. UART 0 is the USB console.
     Source: locker-firmware/README.md#hardware, components/lc_serial_uart/Kconfig. -->

## Converter to lock panel

<!-- A to A, B to B. Panel DIP address 1. 9600 baud 8N1. -->

## Power

<!-- Panel has its own supply; check the seller's voltage. Flyback diodes across locks. -->

## Before you power on

<!-- Checklist: polarity, A/B not swapped, no lock on a channel you will test first, etc. -->
