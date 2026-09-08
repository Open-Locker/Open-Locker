---
name: iot-hardware
description: Use when working on locker-client, MQTT messages, Modbus communication, provisioning, simulator behavior, hardware mappings, or AsyncAPI fixtures.
---

# IoT and hardware workflow

`locker-client/` owns physical communication: it subscribes to MQTT, serializes
Modbus operations, tolerates unreachable boards as designed, and publishes
structured responses/state. The backend publishes commands and handles replies;
it does not speak Modbus. Preserve message-id/transaction-id separation and
credential/provisioning boundaries.

Keep `docs/asyncapi/mqtt.yaml`, JSON schemas, examples, backend publishers, and
client handlers aligned. Prefer simulator/integration tests for protocol and
hardware behavior, and mock the boundary in backend tests. Run the narrowest
locker-client build/typecheck/test set that covers the change.
