import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import {
  applyConfigCommandSchema,
  knownMQTTCommandSchema,
  mqttCommandEnvelopeSchema,
  openCompartmentCommandSchema,
  provisioningErrorResponseSchema,
  provisioningRequestSchema,
  provisioningResponseSchema,
  provisioningSuccessResponseSchema,
} from '../../src/domain/mqtt-schemas';
import { parseKnownMQTTCommand } from '../../src/domain/mqtt-parsing';
import { readAsyncApiExample } from '../contract/jsonSchema';

const commandExampleFiles = ['command-open-compartment.json', 'command-apply-config.json'] as const;

for (const exampleFile of commandExampleFiles) {
  test(`${exampleFile} includes a non-empty transaction_id`, () => {
    const example = readAsyncApiExample(exampleFile) as Record<string, unknown>;
    const transactionId = example.transaction_id;
    if (typeof transactionId !== 'string') {
      assert.fail('transaction_id must be a string');
    }
    assert.ok(transactionId.trim().length > 0);
    assert.equal(mqttCommandEnvelopeSchema.safeParse(example).success, true);
  });
}

test('known command schemas reject missing transaction_id', () => {
  const base = {
    message_id: 'msg-1',
    timestamp: '2026-04-11T10:00:00Z',
  };

  assert.equal(
    openCompartmentCommandSchema.safeParse({
      ...base,
      action: 'open_compartment',
      data: { compartment_number: 1 },
    }).success,
    false,
  );
  assert.equal(
    applyConfigCommandSchema.safeParse({
      ...base,
      action: 'apply_config',
      data: {
        config_hash: 'a'.repeat(64),
        heartbeat_interval_seconds: 30,
        compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
      },
    }).success,
    false,
  );
});

test('parseKnownMQTTCommand returns null when transaction_id is missing', () => {
  assert.equal(
    parseKnownMQTTCommand({
      action: 'open_compartment',
      message_id: 'msg-1',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
    null,
  );
});

test('every AsyncAPI command example validates against knownMQTTCommandSchema', () => {
  const examplesDirectory = path.resolve(process.cwd(), '..', 'docs', 'asyncapi', 'examples');
  const commandExamples = fs
    .readdirSync(examplesDirectory)
    .filter((fileName) => fileName.startsWith('command-') && fileName.endsWith('.json'));

  for (const fileName of commandExamples) {
    const example = readAsyncApiExample(fileName);
    assert.equal(
      knownMQTTCommandSchema.safeParse(example).success,
      true,
      `${fileName} must validate as a known inbound command with transaction_id`,
    );
  }
});

test('provisioning-request.json validates against provisioningRequestSchema', () => {
  const example = readAsyncApiExample('provisioning-request.json');
  assert.equal(provisioningRequestSchema.safeParse(example).success, true);
});

test('provisioning-success.json validates against provisioningSuccessResponseSchema', () => {
  const example = readAsyncApiExample('provisioning-success.json');
  assert.equal(provisioningSuccessResponseSchema.safeParse(example).success, true);
  assert.equal(provisioningResponseSchema.safeParse(example).success, true);
});

test('provisioning-error.json validates against provisioningErrorResponseSchema', () => {
  const example = readAsyncApiExample('provisioning-error.json');
  assert.equal(provisioningErrorResponseSchema.safeParse(example).success, true);
  assert.equal(provisioningResponseSchema.safeParse(example).success, true);
});
