import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertSecureProductionMqttUrl } from '../../src/infrastructure/mqtt-url-policy';

test('accepts MQTTS in production', () => {
  assert.doesNotThrow(() =>
    assertSecureProductionMqttUrl('mqtts://mqtt.example.com:8883', {
      NODE_ENV: 'production',
    }),
  );
});

test('rejects plaintext MQTT in production', () => {
  assert.throws(
    () =>
      assertSecureProductionMqttUrl('mqtt://mqtt.example.com:1883', {
        APP_ENV: 'production',
      }),
    /must use mqtts:\/\//,
  );
});

test('keeps local plaintext development available', () => {
  assert.doesNotThrow(() =>
    assertSecureProductionMqttUrl('mqtt://localhost:1883', {
      NODE_ENV: 'development',
    }),
  );
});
