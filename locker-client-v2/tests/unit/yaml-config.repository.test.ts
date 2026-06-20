import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, before, test } from 'node:test';
import { YamlConfigRepository } from '../../src/adapters/config/yaml-config.repository';
import { MemoryOverlayStore } from '../helpers/memory-overlay-store';

let tempDir = '';
let configFile = '';

before(() => {
  tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'locker-client-v2-config-'));
  configFile = path.join(tempDir, 'locker-config.yml');
});

after(() => {
  fs.rmSync(tempDir, { recursive: true, force: true });
});

test('YamlConfigRepository ignores legacy yaml compartments and heartbeatInterval', () => {
  fs.writeFileSync(
    configFile,
    [
      'modbus:',
      '  port: /dev/ttyTEST',
      'compartments:',
      '  - compartment_number: 9',
      '    slaveId: 9',
      '    address: 0',
      'mqtt:',
      '  heartbeatInterval: 99',
    ].join('\n'),
    'utf8',
  );

  const repository = new YamlConfigRepository(new MemoryOverlayStore(), configFile);
  const effective = repository.load();

  assert.equal(effective.compartments, undefined);
  assert.equal(effective.mqtt?.heartbeatInterval, undefined);
  assert.deepEqual(repository.getConfiguredSlaveIds(), []);
});

test('YamlConfigRepository uses runtime overlay for compartments and heartbeat', () => {
  fs.writeFileSync(configFile, ['modbus:', '  port: /dev/ttyTEST'].join('\n'), 'utf8');

  const overlayStore = new MemoryOverlayStore({
    mqtt: { heartbeatInterval: 30 },
    compartments: [
      { compartment_number: 1, slaveId: 1, address: 0 },
      { compartment_number: 2, slaveId: 2, address: 1 },
    ],
  });
  const repository = new YamlConfigRepository(overlayStore, configFile);
  const effective = repository.load();

  assert.equal(effective.mqtt?.heartbeatInterval, 30);
  assert.deepEqual(repository.getConfiguredSlaveIds(), [1, 2]);
  assert.deepEqual(effective.compartments, overlayStore.load()?.compartments);
});

test('YamlConfigRepository returns empty slave ids for explicit empty runtime mapping', () => {
  fs.writeFileSync(configFile, ['modbus:', '  port: /dev/ttyTEST'].join('\n'), 'utf8');

  const repository = new YamlConfigRepository(
    new MemoryOverlayStore({
      compartments: [],
    }),
    configFile,
  );

  assert.deepEqual(repository.getConfiguredSlaveIds(), []);
  assert.deepEqual(repository.load().compartments, []);
});
