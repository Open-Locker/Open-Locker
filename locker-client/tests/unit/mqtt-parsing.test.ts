import assert from 'node:assert/strict';
import { test } from 'node:test';
import { z } from 'zod';
import { knownMQTTCommandSchema } from '../../src/domain/mqtt-schemas';
import {
  formatZodValidationError,
  MqttSchemaValidationError,
  parseKnownMQTTCommand,
  parseProvisioningResponse,
} from '../../src/domain/mqtt-parsing';
import { readAsyncApiExample } from '../contract/jsonSchema';

test('formatZodValidationError returns flattened field errors', () => {
  const schema = z.object({
    action: z.literal('open_compartment'),
    message_id: z.string().min(1),
  });
  const parsed = schema.safeParse({ action: 'apply_config' });

  assert.equal(parsed.success, false);
  if (parsed.success) {
    assert.fail('Expected schema validation to fail');
  }

  const formatted = formatZodValidationError(parsed.error);
  assert.ok('fieldErrors' in formatted || 'formErrors' in formatted);
});

test('parseKnownMQTTCommand returns null for malformed commands', () => {
  assert.equal(
    parseKnownMQTTCommand({
      action: 'open_compartment',
      message_id: 'msg-1',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 'not-a-number' },
    }),
    null,
  );
});

test('parseKnownMQTTCommand accepts AsyncAPI open_compartment example', () => {
  const example = readAsyncApiExample('command-open-compartment.json');
  const parsed = parseKnownMQTTCommand(example);

  assert.ok(parsed);
  assert.equal(parsed?.action, 'open_compartment');
  assert.equal(knownMQTTCommandSchema.safeParse(example).success, true);
});

test('parseProvisioningResponse throws MqttSchemaValidationError for malformed replies', () => {
  assert.throws(
    () =>
      parseProvisioningResponse({
        status: 'success',
        timestamp: '2026-01-01T00:00:00.000Z',
        data: {
          mqtt_user: 'mqtt-user',
        },
      }),
    (error: unknown) => error instanceof MqttSchemaValidationError,
  );
});
