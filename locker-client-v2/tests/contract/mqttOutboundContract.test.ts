import { test } from 'node:test';
import { serializeOutboundPayload } from '../../src/adapters/mqtt/outbound-envelope';
import { connectionLostWillOptions } from '../../src/infrastructure/mqtt-will';
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
        ],
      },
      () => '2026-04-14T19:36:05Z',
    ),
  );

  assertMatchesSchema('payloads/state-snapshot.json', payload);
});

test('command success response matches AsyncAPI schema', () => {
  const payload = JSON.parse(
    serializeOutboundPayload(
      {
        type: 'command_response',
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

test('connection lost will payload matches AsyncAPI schema', () => {
  const will = connectionLostWillOptions('locker-test', () => '2026-04-14T19:36:00Z').will;
  const payload = JSON.parse(String(will?.payload));

  assertMatchesSchema('payloads/state-connection-lost.json', payload);
});
