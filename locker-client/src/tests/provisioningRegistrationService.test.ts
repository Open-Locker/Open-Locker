import assert from "node:assert/strict";
import { EventEmitter } from "node:events";
import test from "node:test";
import type { MqttClient } from "mqtt";
import { mqttClientManager } from "../mqtt/mqttClientManager";
import { credentialsService } from "../services/credentialsService";
import {
  parseProvisioningResponse,
  ProvisioningRegistrationService,
} from "../services/provisioningRegistrationService";
import { provisioningService as provisioningStateService } from "../services/provisioningService";

class FakeMqttClient extends EventEmitter {
  connected = true;
  published: Array<{ topic: string; payload: string }> = [];

  publish(
    topic: string,
    payload: string,
    _opts: Record<string, unknown>,
    callback: (error?: Error) => void,
  ): void {
    this.published.push({ topic, payload });
    callback();
  }

  subscribe(
    _topic: string,
    _opts: Record<string, unknown>,
    callback: (error?: Error) => void,
  ): void {
    callback();
  }
}

test("parseProvisioningResponse accepts contract-shaped success replies", () => {
  const response = parseProvisioningResponse({
    message_id: "reply-1",
    status: "success",
    timestamp: "2026-01-01T00:00:00.000Z",
    data: {
      mqtt_user: "mqtt-user",
      mqtt_password: "mqtt-password",
    },
  });

  assert.equal(response.status, "success");
  if (response.status !== "success") {
    assert.fail("Expected provisioning success response");
  }
  assert.equal(response.data.mqtt_user, "mqtt-user");
});

test("parseProvisioningResponse accepts contract-shaped error replies", () => {
  const response = parseProvisioningResponse({
    message_id: "reply-1",
    status: "error",
    timestamp: "2026-01-01T00:00:00.000Z",
    message: "Invalid or expired provisioning token.",
  });

  assert.equal(response.status, "error");
  if (response.status !== "error") {
    assert.fail("Expected provisioning error response");
  }
  assert.equal(response.message, "Invalid or expired provisioning token.");
});

test("parseProvisioningResponse rejects malformed replies", () => {
  assert.throws(
    () =>
      parseProvisioningResponse({
        status: "success",
        timestamp: "2026-01-01T00:00:00.000Z",
        data: {
          mqtt_user: "mqtt-user",
        },
      }),
    /Malformed provisioning response/,
  );
});

test("register publishes provisioning request with top-level timestamp", async () => {
  const fakeClient = new FakeMqttClient();
  const originalGetClient = mqttClientManager.getClient.bind(mqttClientManager);
  const originalSaveCredentials = credentialsService.saveCredentials.bind(credentialsService);
  const originalMarkAsProvisioned = provisioningStateService.markAsProvisioned.bind(
    provisioningStateService,
  );

  let savedCredentials: { username: string; password: string } | null = null;
  let markedProvisioned = false;

  mqttClientManager.getClient = () => fakeClient as unknown as MqttClient;
  credentialsService.saveCredentials = (username: string, password: string) => {
    savedCredentials = { username, password };
  };
  provisioningStateService.markAsProvisioned = () => {
    markedProvisioned = true;
  };

  try {
    const registration = new ProvisioningRegistrationService();
    const registrationPromise = registration.register("token-1", "prov-client-1");

    setImmediate(() => {
      fakeClient.emit(
        "message",
        "locker/provisioning/reply/prov-client-1",
        Buffer.from(
          JSON.stringify({
            message_id: "reply-1",
            status: "success",
            timestamp: "2026-01-01T00:00:00.000Z",
            data: {
              mqtt_user: "mqtt-user",
              mqtt_password: "mqtt-password",
            },
          }),
        ),
      );
    });

    await assert.doesNotReject(registrationPromise);

    assert.equal(fakeClient.published.length, 1);
    assert.equal(fakeClient.published[0].topic, "locker/register/token-1");

    const payload = JSON.parse(fakeClient.published[0].payload) as {
      client_id?: string;
      message_id?: string;
      timestamp?: string;
    };

    assert.equal(payload.client_id, "prov-client-1");
    assert.equal(typeof payload.message_id, "string");
    assert.equal(typeof payload.timestamp, "string");
    assert.ok(payload.timestamp);
    assert.deepEqual(savedCredentials, {
      username: "mqtt-user",
      password: "mqtt-password",
    });
    assert.equal(markedProvisioned, true);
  } finally {
    mqttClientManager.getClient = originalGetClient;
    credentialsService.saveCredentials = originalSaveCredentials;
    provisioningStateService.markAsProvisioned = originalMarkAsProvisioned;
  }
});
