import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  parseProvisioningResponse,
  provisionDevice,
} from '../../src/application/provision-device';
import type { CredentialStorePort } from '../../src/ports/config.port';
import type { MessageTransportPort, MqttTransportSettings } from '../../src/ports/mqtt.port';
import { assertMatchesSchema, readAsyncApiExample } from '../contract/jsonSchema';

class FakeMessageTransport implements MessageTransportPort {
  published: Array<{ topic: string; payload: string }> = [];
  private messageHandler: ((topic: string, payload: Buffer) => void) | null = null;

  async connect(): Promise<void> {}

  async disconnect(): Promise<void> {}

  async subscribe(): Promise<void> {}

  async publish(topic: string, payload: string): Promise<void> {
    this.published.push({ topic, payload });
  }

  onMessage(handler: (topic: string, payload: Buffer) => void): void {
    this.messageHandler = handler;
  }

  emitMessage(topic: string, payload: Record<string, unknown>): void {
    this.messageHandler?.(topic, Buffer.from(JSON.stringify(payload)));
  }

  getConnectionState() {
    return 'connected' as const;
  }

  getTransportSettings(): MqttTransportSettings {
    return {
      clean: false,
      keepalive: 60,
      reconnectPeriod: 5000,
      connectTimeout: 30000,
      maxReconnectAttempts: 0,
    };
  }
}

class FakeCredentialStore implements CredentialStorePort {
  savedCredentials: { username: string; password: string } | null = null;
  provisioned = false;

  getCredentials() {
    return this.savedCredentials;
  }

  saveCredentials(credentials: { username: string; password: string }): void {
    this.savedCredentials = credentials;
  }

  isProvisioned(): boolean {
    return this.provisioned;
  }

  markProvisioned(): void {
    this.provisioned = true;
  }
}

test('parseProvisioningResponse accepts AsyncAPI success example', () => {
  const example = readAsyncApiExample('provisioning-success.json');
  assertMatchesSchema('payloads/provisioning-success.json', example);

  const response = parseProvisioningResponse(example);
  assert.equal(response.status, 'success');
  if (response.status !== 'success') {
    assert.fail('Expected provisioning success response');
  }

  assert.equal(response.data.mqtt_user, '11111111-1111-1111-1111-111111111111');
  assert.equal(response.data.mqtt_password, 'super-secret-password');
});

test('parseProvisioningResponse accepts AsyncAPI error example', () => {
  const example = readAsyncApiExample('provisioning-error.json');
  assertMatchesSchema('payloads/provisioning-error.json', example);

  const response = parseProvisioningResponse(example);
  assert.equal(response.status, 'error');
  if (response.status !== 'error') {
    assert.fail('Expected provisioning error response');
  }

  assert.equal(response.message, 'Invalid or expired provisioning token.');
});

test('parseProvisioningResponse rejects malformed replies', () => {
  assert.throws(
    () =>
      parseProvisioningResponse({
        status: 'success',
        timestamp: '2026-01-01T00:00:00.000Z',
        data: {
          mqtt_user: 'mqtt-user',
        },
      }),
    /Malformed provisioning response/,
  );
});

test('provisionDevice saves credentials from contract-shaped success reply', async () => {
  const previousUsername = process.env.MQTT_DEFAULT_USERNAME;
  const previousPassword = process.env.MQTT_DEFAULT_PASSWORD;
  process.env.MQTT_DEFAULT_USERNAME = 'default-user';
  process.env.MQTT_DEFAULT_PASSWORD = 'default-password';

  const transport = new FakeMessageTransport();
  const credentialStore = new FakeCredentialStore();

  try {
    const provisionPromise = provisionDevice({
      transport,
      brokerUrl: 'mqtt://localhost',
      clientId: 'prov-client-1',
      provisioningToken: 'token-1',
      credentialStore,
    });

    setImmediate(() => {
      transport.emitMessage('locker/provisioning/reply/prov-client-1', {
        message_id: 'reply-1',
        status: 'success',
        timestamp: '2026-01-01T00:00:00.000Z',
        data: {
          mqtt_user: 'mqtt-user',
          mqtt_password: 'mqtt-password',
        },
      });
    });

    await assert.doesNotReject(provisionPromise);

    assert.equal(transport.published.length, 1);
    assert.equal(transport.published[0].topic, 'locker/register/token-1');

    const payload = JSON.parse(transport.published[0].payload) as {
      client_id?: string;
      message_id?: string;
      timestamp?: string;
    };

    assert.equal(payload.client_id, 'prov-client-1');
    assert.equal(typeof payload.message_id, 'string');
    assert.equal(typeof payload.timestamp, 'string');
    assertMatchesSchema('messages/provisioning-request.json', payload);
    assert.deepEqual(credentialStore.savedCredentials, {
      username: 'mqtt-user',
      password: 'mqtt-password',
    });
    assert.equal(credentialStore.provisioned, true);
  } finally {
    if (previousUsername === undefined) {
      delete process.env.MQTT_DEFAULT_USERNAME;
    } else {
      process.env.MQTT_DEFAULT_USERNAME = previousUsername;
    }

    if (previousPassword === undefined) {
      delete process.env.MQTT_DEFAULT_PASSWORD;
    } else {
      process.env.MQTT_DEFAULT_PASSWORD = previousPassword;
    }
  }
});

test('provisionDevice rejects contract-shaped error reply', async () => {
  const previousUsername = process.env.MQTT_DEFAULT_USERNAME;
  const previousPassword = process.env.MQTT_DEFAULT_PASSWORD;
  process.env.MQTT_DEFAULT_USERNAME = 'default-user';
  process.env.MQTT_DEFAULT_PASSWORD = 'default-password';

  const transport = new FakeMessageTransport();

  try {
    const provisionPromise = provisionDevice({
      transport,
      brokerUrl: 'mqtt://localhost',
      clientId: 'prov-client-1',
      provisioningToken: 'token-1',
      credentialStore: new FakeCredentialStore(),
    });

    setImmediate(() => {
      transport.emitMessage('locker/provisioning/reply/prov-client-1', {
        message_id: 'reply-1',
        status: 'error',
        timestamp: '2026-01-01T00:00:00.000Z',
        message: 'Invalid or expired provisioning token.',
      });
    });

    await assert.rejects(provisionPromise, /Invalid or expired provisioning token\./);
  } finally {
    if (previousUsername === undefined) {
      delete process.env.MQTT_DEFAULT_USERNAME;
    } else {
      process.env.MQTT_DEFAULT_USERNAME = previousUsername;
    }

    if (previousPassword === undefined) {
      delete process.env.MQTT_DEFAULT_PASSWORD;
    } else {
      process.env.MQTT_DEFAULT_PASSWORD = previousPassword;
    }
  }
});
