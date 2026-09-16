# Locker as a Service

Open-Locker provides a central service for managing locker banks. Operators
configure locker banks in the Laravel admin panel; users access compartments
through the mobile app.

The deployed system consists of:

- the Laravel API and admin panel;
- PostgreSQL, Redis, queues, and Laravel Reverb;
- Mosquitto with backend-provided authentication and ACL checks;
- the Dockerized `locker-client` at each location.

The backend and locker client communicate through MQTT. The backend publishes
commands and provisioning replies, while the locker client publishes responses,
device events, heartbeats, connection signals, and retained compartment
snapshots. The locker client translates commands into serialized Modbus
operations against the physical hardware.

The canonical topic and payload contract is
[`asyncapi/mqtt.yaml`](asyncapi/mqtt.yaml). Do not define MQTT topics in this
service overview. Deployment and locker-client setup are documented in
[`Installation.md`](Installation.md).
