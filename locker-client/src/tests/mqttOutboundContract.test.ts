import test from "node:test";
import { connectionLostWillOptions } from "../helper/mqttWill";
import { prepareMQTTPayload } from "../services/mqttService";
import { assertMatchesSchema } from "./contracts/jsonSchema";

test("provisioning request payload matches AsyncAPI schema", () => {
  const payload = JSON.parse(
    prepareMQTTPayload({
      client_id: "provisioning-client-xyz789",
      timestamp: "2026-04-14T19:35:00Z",
    }),
  );

  assertMatchesSchema("messages/provisioning-request.json", payload);
});

test("heartbeat payload matches AsyncAPI schema", () => {
  const payload = JSON.parse(
    prepareMQTTPayload({
      timestamp: "2026-04-14T19:36:00Z",
      uptime_seconds: 60,
    }),
  );

  assertMatchesSchema("payloads/state-heartbeat.json", payload);
});

test("compartment snapshot payload matches AsyncAPI schema", () => {
  const payload = JSON.parse(
    prepareMQTTPayload({
      timestamp: "2026-04-14T19:36:05Z",
      compartments: [
        { compartment_number: 1, door_state: "closed" },
        { compartment_number: 2, door_state: "open" },
      ],
    }),
  );

  assertMatchesSchema("payloads/state-snapshot.json", payload);
});

test("connection lost will payload matches AsyncAPI schema", () => {
  const will = connectionLostWillOptions("locker-test").will;
  const payload = JSON.parse(String(will?.payload));

  assertMatchesSchema("payloads/state-connection-lost.json", payload);
});
