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
  tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'locker-client-config-'));
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

function writeConfig(lines: string[]): void {
  fs.writeFileSync(configFile, lines.join('\n'), 'utf8');
}

test('a transport setting outside its bounds fails at load, not hours later', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST', 'mqtt:', '  connectTimeoutMs: 0']);

  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /connectTimeoutMs must be between/,
  );
});

test('a non-numeric transport setting is rejected', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST', 'mqtt:', '  keepaliveSeconds: soon']);

  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /keepaliveSeconds must be a number/,
  );
});

test('sentinel values that mean "disabled" stay allowed', () => {
  writeConfig([
    'modbus:',
    '  port: /dev/ttyTEST',
    'mqtt:',
    '  keepaliveSeconds: 0',
    '  reconnectPeriodMs: 0',
    '  maxReconnectAttempts: 0',
  ]);

  const config = new YamlConfigRepository(new MemoryOverlayStore(), configFile).load();

  assert.equal(config.modbus.port, '/dev/ttyTEST');
});

test('a heartbeat the admin panel can legally set is accepted', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST']);

  const overlay = new MemoryOverlayStore();
  // Above the ceiling an earlier revision invented; the inbound schema is
  // `positive()` and the panel sets only a minimum, so this must load.
  overlay.save({ mqtt: { heartbeatInterval: 600 }, updatedAt: '2026-08-20T00:00:00Z' });

  const config = new YamlConfigRepository(overlay, configFile).load();

  assert.equal(config.mqtt?.heartbeatInterval, 600);
});

test('a corrupted overlay heartbeat is caught rather than reaching setInterval', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST']);

  const overlay = new MemoryOverlayStore();
  overlay.save({ mqtt: { heartbeatInterval: 0 }, updatedAt: '2026-08-20T00:00:00Z' });

  assert.throws(
    () => new YamlConfigRepository(overlay, configFile).load(),
    /heartbeatInterval must be between/,
  );
});

test('a nonsensical baud rate fails at load rather than skewing RTU pacing', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST', '  baudRate: 12']);

  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /baudRate must be between/,
  );
});

test('a non-numeric baud rate is rejected', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST', '  baudRate: fast']);

  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /baudRate must be a number/,
  );
});

test('serial framing values outside the driver set are rejected', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST', '  dataBits: 9']);

  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /dataBits must be one of 7, 8/,
  );
});

test('an unusable serial timeout is rejected', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST', '  timeout: 0']);

  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /timeout must be between/,
  );
});

test('a fractional heartbeat is rejected, matching the inbound schema', () => {
  writeConfig(['modbus:', '  port: /dev/ttyTEST']);

  const overlay = new MemoryOverlayStore();
  overlay.save({ mqtt: { heartbeatInterval: 1.5 }, updatedAt: '2026-08-20T00:00:00Z' });

  assert.throws(
    () => new YamlConfigRepository(overlay, configFile).load(),
    /heartbeatInterval must be a whole number/,
  );
});

test('a reconnect cooldown outside its bounds fails at load', () => {
  // Too short turns recovery into a retry loop against dead hardware; too long
  // delays recovery after a brief blip. Both are caught here rather than in the
  // field.
  writeConfig(['modbus:', '  port: /dev/ttyTEST', '  reconnectCooldownSeconds: 1']);
  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /reconnectCooldownSeconds must be between/,
  );

  writeConfig(['modbus:', '  port: /dev/ttyTEST', '  reconnectCooldownSeconds: 7200']);
  assert.throws(
    () => new YamlConfigRepository(new MemoryOverlayStore(), configFile).load(),
    /reconnectCooldownSeconds must be between/,
  );
});
