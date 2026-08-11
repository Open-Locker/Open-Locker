import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { getOrCreateClientId, provisionDevice } from '../../src/application/provision-device';
import {
  MqttSchemaValidationError,
  parseProvisioningResponse,
} from '../../src/domain/mqtt-parsing';
import { PersistentStateCorruptedError } from '../../src/infrastructure/file-persistence';
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

  onConnected(_handler: () => void | Promise<void>): void {}

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

  getCredentials() {
    return this.savedCredentials;
  }

  saveCredentials(credentials: { username: string; password: string }): void {
    this.savedCredentials = credentials;
  }

  isProvisioned(): boolean {
    return this.savedCredentials !== null;
  }
}

test('client ID is created atomically and reused', (t) => {
  const file = createClientIdFile(t);

  const created = getOrCreateClientId(file);
  const reused = getOrCreateClientId(file);

  assert.match(created, /^locker-client-[a-f0-9]{8}$/);
  assert.equal(reused, created);
  assert.deepEqual(fs.readdirSync(path.dirname(file)), [path.basename(file)]);
});

test('empty client ID fails closed and remains available for operator recovery', (t) => {
  const file = createClientIdFile(t);
  fs.writeFileSync(file, '  \n', 'utf8');

  assert.throws(
    () => getOrCreateClientId(file),
    (error: unknown) =>
      error instanceof PersistentStateCorruptedError && error.stateType === 'MQTT client ID',
  );
  assert.equal(fs.readFileSync(file, 'utf8'), '  \n');
});

test('invalid client ID fails closed instead of silently replacing identity', (t) => {
  const file = createClientIdFile(t);
  fs.writeFileSync(file, 'invalid client id', 'utf8');

  assert.throws(() => getOrCreateClientId(file), PersistentStateCorruptedError);
  assert.equal(fs.readFileSync(file, 'utf8'), 'invalid client id');
});

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
    (error: unknown) => {
      assert.ok(error instanceof MqttSchemaValidationError);
      assert.match(error.message, /Malformed provisioning response/);
      assert.ok('fieldErrors' in error.validationErrors || 'formErrors' in error.validationErrors);
      return true;
    },
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
    assert.equal(credentialStore.isProvisioned(), true);
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

function createClientIdFile(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-client-id-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return path.join(directory, '.mqtt-client-id');
}
