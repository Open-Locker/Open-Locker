---
title: Flash the firmware
description: Put the Open Locker firmware on the ESP32.
sidebar:
  order: 4
---

## Prebuilt firmware

<!-- Only possible once CI publishes release binaries; then a browser flasher
     (ESP Web Tools) needs nothing installed. Open question. -->

## Build from source

<!-- ESP-IDF 6.1 shell or the espressif/idf:v6.1 Docker image; idf.py build / flash.
     Source: locker-firmware/README.md#build-and-test. -->

## Release, development and production builds

<!-- Plain build refuses mqtt://; sdkconfig.dev allows it; sdkconfig.production
     removes the bench console. -->

## Open the serial console

<!-- idf.py -p COM3 monitor at 115200 baud; input is not echoed. -->
