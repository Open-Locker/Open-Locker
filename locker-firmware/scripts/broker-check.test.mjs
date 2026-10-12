/* Tests for the pure parts of scripts/broker-check.mjs: it must hash as the device does. */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { applyConfigCommand, canonicalConfig, configHash, openCommand, parseCompartments } from './broker-check.mjs';

const EXAMPLE = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  'docs',
  'asyncapi',
  'examples',
  'command-apply-config.json',
);

test('the hash of the example mapping is the one in the AsyncAPI example', () => {
  const example = JSON.parse(fs.readFileSync(EXAMPLE, 'utf8'));
  const { feedback_type: feedback, compartments, config_hash: hash } = example.data;

  assert.equal(configHash(feedback, compartments), hash);
});

test('the canonical form sorts by compartment number and has no whitespace', () => {
  const compartments = parseCompartments('7:1:6,1:1:0');

  assert.equal(
    canonicalConfig('door_opening', compartments),
    '{"adapter_type":"rs485_lock_board","feedback_type":"door_opening","compartments":[{"compartment_number":1,"slaveId":1,"address":0},{"compartment_number":7,"slaveId":1,"address":6}]}',
  );
});

test('a malformed mapping is refused', () => {
  assert.throws(() => parseCompartments('1:1'), /not number:board:address/);
  assert.throws(() => parseCompartments('a:b:c'), /not number:board:address/);
});

test('the commands carry what the device expects', () => {
  const apply = applyConfigCommand({
    transactionId: 't1',
    feedbackType: 'door_closing',
    heartbeat: 15,
    compartments: parseCompartments('1:1:0'),
  });
  const open = openCommand({ transactionId: 't2', messageId: 'm2', compartment: 3 });

  assert.equal(apply.action, 'apply_config');
  assert.equal(apply.data.config_hash, configHash('door_closing', apply.data.compartments));
  assert.equal(open.message_id, 'm2');
  assert.equal(open.data.compartment_number, 3);
});
