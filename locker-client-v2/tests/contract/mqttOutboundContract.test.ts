import assert from 'node:assert/strict';
import { test } from 'node:test';
import { serializeOutboundPayload } from '../../src/adapters/mqtt/outbound-envelope';
import { connectionLostWillOptions } from '../../src/infrastructure/mqtt-will';
import { MqttErrorCode } from '../../src/domain/errors';
import { assertMatchesSchema } from './jsonSchema';

test('heartbeat payload matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload({ uptime_seconds: 60 }, () => '2026-04-14T19:36:00Z'),
  );

  assertMatchesSchema('payloads/state-heartbeat.json', payload);
});

test('compartment snapshot payload matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        compartments: [
          { compartment_number: 1, door_state: 'closed' },
          { compartment_number: 2, door_state: 'open' },
          { compartment_number: 3, door_state: 'unknown' },
        ],
      },
      () => '2026-04-14T19:36:05Z',
    ),
  );

  assertMatchesSchema('payloads/state-snapshot.json', payload);
});

test('open_compartment success response matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        action: 'open_compartment',
        result: 'success',
        transaction_id: 'tx-1',
        message: 'Compartment opened.',
      },
      () => '2026-04-14T19:36:05Z',
    ),
  );

  assertMatchesSchema('payloads/response-command-success.json', payload);
});

test('apply_config success response matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        action: 'apply_config',
        result: 'success',
        transaction_id: 'tx-config-1',
        applied_config_hash: 'a'.repeat(64),
        message: 'Config applied.',
      },
      () => '2026-04-14T19:36:05Z',
    ),
  );

  assertMatchesSchema('payloads/response-apply-config-success.json', payload);
});

test('command error response matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        action: 'open_compartment',
        result: 'error',
        transaction_id: 'tx-1',
        error_code: 'DOOR_JAMMED',
        message: 'Could not open compartment, mechanism is jammed.',
      },
      () => '2026-04-14T19:36:05Z',
    ),
  );

  assertMatchesSchema('payloads/response-command-error.json', payload);
});

test('dispatcher validation error response matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        action: 'open_compartment',
        result: 'error',
        transaction_id: 'txn-invalid',
        error_code: 'INVALID_COMMAND',
        message: 'Command validation failed',
      },
      () => '2026-04-14T19:36:05Z',
    ),
  );

  assertMatchesSchema('payloads/response-command-error.json', payload);
});

test('every MqttErrorCode produces a schema-valid error response', () => {
  for (const errorCode of Object.values(MqttErrorCode)) {
    const payload = JSON.parse(
      serializeOutboundPayload(
        {
          action: 'open_compartment',
          result: 'error',
          transaction_id: 'tx-error',
          error_code: errorCode,
          message: `Simulated ${errorCode} failure.`,
        },
        () => '2026-04-14T19:36:05Z',
      ),
    );

    assertMatchesSchema('payloads/response-command-error.json', payload);
  }
});

test('connection lost will payload matches AsyncAPI schema', () => {
  const will = connectionLostWillOptions('locker-test', () => '2026-04-14T19:36:00Z').will;
  const payload = JSON.parse(String(will?.payload));

  assertMatchesSchema('payloads/state-connection-lost.json', payload);
});

test('provisioning register request matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        client_id: 'locker-client-a1b2c3d4',
      },
      () => '2026-04-14T19:36:00Z',
    ),
  );

  assertMatchesSchema('messages/provisioning-request.json', payload);
});
