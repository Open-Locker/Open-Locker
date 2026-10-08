# ESP32 integration implementation status

The August plan remains on `docs/esp32-client-plan`; this document reconciles its
hardware assumptions with the October dedicated RS485 bench milestone. ADR-0068
and ADR-0069 record the implemented scope without reusing its ADR-0054 number.

Implemented modules in `locker-client-esp32/` cover pure protocol/contracts,
append-only command recovery, Wi-Fi/time, verified MQTT, physical USB
commissioning, sole UART ownership, and serialized command/config/state behavior.
The independent manual project has been preserved under `hardware/esp32/`.
Shared JSON schemas and backend/mobile interfaces are unchanged.

The first image targets D1 mini/WROOM-32, XY-485 auto direction, one panel and
64 compartments. It rejects Waveshare profiles. PR #312 remains an independent
Pi change: this implementation adopts no-retry unlock semantics without removing
the current Pi path. Sensor state remains independently observed.

Host tests and firmware CI provide automated evidence. Physical milestone
acceptance is tracked in `locker-client-esp32/HARDWARE_ACCEPTANCE.md`, including
USB setup validation, enrollment/ACL recovery, fault injection, panel timing/polarity,
power cuts and Pi restoration. No completed checkbox is implied by a build.

Follow-on production work must resolve journal retention/capacity/compaction,
anti-rollback protection, authenticated time, final board/enclosure qualification,
secure boot/flash encryption, OTA partition/signature/readiness policy under #59
and #70, then pilot device-hours/SLOs and soak evidence under #56. The bounded
64-transaction, non-compacting factory image is an attended bench implementation.

See [#56](https://github.com/Open-Locker/Open-Locker/issues/56),
[#307](https://github.com/Open-Locker/Open-Locker/issues/307),
[PR #312](https://github.com/Open-Locker/Open-Locker/pull/312), and the firmware
README for current commands and limits. This document does not replace accepted
MQTT, provisioning or hardware-profile contracts.
