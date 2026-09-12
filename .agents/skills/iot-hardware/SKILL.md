---
name: iot-hardware
description: Use for locker-client, MQTT messages, Modbus, provisioning, simulators, hardware mappings, or AsyncAPI fixtures.
---

`locker-client/` owns physical communication: MQTT subscriptions, serialized
Modbus operations, reachability handling, and structured responses. The backend
publishes commands and handles replies but does not speak Modbus. Keep AsyncAPI
schemas/examples, publishers, handlers, and simulator tests aligned. Mock the
boundary in backend tests and run the narrowest relevant client checks.
