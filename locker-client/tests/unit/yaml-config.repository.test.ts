import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { FileRuntimeOverlayStore } from '../../src/adapters/config/runtime-overlay.store';
import { YamlConfigRepository } from '../../src/adapters/config/yaml-config.repository';
import type { LoggerPort } from '../../src/ports/logging.port';

test('a legacy overlay without a hardware profile selects no adapter', (t) => {
  const { configPath, overlayPath } = createConfigFiles(t);
  fs.writeFileSync(
    overlayPath,
    JSON.stringify({
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    }),
  );

  const effective = new YamlConfigRepository(
    new FileRuntimeOverlayStore(overlayPath),
    configPath,
  ).load();

  assert.equal(effective.hardwareProfile, undefined);
  assert.equal(effective.compartments?.length, 1);
});

test('a stored Waveshare profile stays readable but selects no adapter', (t) => {
  const { configPath, overlayPath } = createConfigFiles(t);
  fs.writeFileSync(
    overlayPath,
    JSON.stringify({
      hardwareProfile: { adapterType: 'waveshare_modbus', feedbackType: 'door_closing' },
      compartments: [{ compartment_number: 1, slaveId: 40, address: 0 }],
    }),
  );

  const effective = new YamlConfigRepository(
    new FileRuntimeOverlayStore(overlayPath),
    configPath,
  ).load();

  assert.equal(effective.hardwareProfile, undefined);
  assert.equal(effective.compartments?.[0]?.slaveId, 40);
});

test('fresh installation without overlay stays capability-neutral', (t) => {
  const { configPath } = createConfigFiles(t);
  const effective = new YamlConfigRepository(
    new FileRuntimeOverlayStore(path.join(path.dirname(configPath), 'missing-overlay.json')),
    configPath,
  ).load();
  assert.equal(effective.hardwareProfile, undefined);
  assert.equal(effective.compartments, undefined);
});

test('reads the serial device from `serial.port`', (t) => {
  const { configPath } = createConfigFiles(t, 'serial:\n  port: /dev/serial/by-id/usb-rs485\n');
  const { log, warnings } = recordingLog();

  const effective = new YamlConfigRepository(
    new FileRuntimeOverlayStore(path.join(path.dirname(configPath), 'missing-overlay.json')),
    configPath,
    log,
  ).load();

  assert.deepEqual(effective.serial, { port: '/dev/serial/by-id/usb-rs485' });
  assert.deepEqual(warnings, []);
});

test('accepts a deployed `modbus:` block and reports the settings it ignores', (t) => {
  const { configPath } = createConfigFiles(
    t,
    'modbus:\n  port: /dev/ttyACM0\n  baudRate: 19200\n  flashDurationMs: 200\n',
  );
  const { log, warnings } = recordingLog();

  const effective = new YamlConfigRepository(
    new FileRuntimeOverlayStore(path.join(path.dirname(configPath), 'missing-overlay.json')),
    configPath,
    log,
  ).load();

  assert.deepEqual(effective.serial, { port: '/dev/ttyACM0' });
  assert.equal(warnings.length, 2);
  assert.match(warnings[0]!.message, /`modbus:` is deprecated/);
  assert.deepEqual(warnings[1]!.meta, { ignoredKeys: ['baudRate', 'flashDurationMs'] });
});

test('fails at load when no serial device is configured', (t) => {
  const { configPath } = createConfigFiles(t, 'mqtt:\n  keepaliveSeconds: 60\n');

  assert.throws(
    () =>
      new YamlConfigRepository(
        new FileRuntimeOverlayStore(path.join(path.dirname(configPath), 'missing-overlay.json')),
        configPath,
      ).load(),
    /serial\.port is required/,
  );
});

function recordingLog(): {
  log: LoggerPort;
  warnings: Array<{ message: string; meta?: Record<string, unknown> }>;
} {
  const warnings: Array<{ message: string; meta?: Record<string, unknown> }> = [];
  return {
    warnings,
    log: {
      warn: (message, meta) => warnings.push({ message, meta }),
      error: () => undefined,
    },
  };
}

function createConfigFiles(
  t: TestContext,
  contents = 'serial:\n  port: /dev/null\n',
): { configPath: string; overlayPath: string } {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-yaml-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const configPath = path.join(directory, 'locker-config.yml');
  const overlayPath = path.join(directory, 'runtime-overlay.json');
  fs.writeFileSync(configPath, contents);
  return { configPath, overlayPath };
}
