import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { FileRuntimeOverlayStore } from '../../src/adapters/config/runtime-overlay.store';
import { YamlConfigRepository } from '../../src/adapters/config/yaml-config.repository';

test('legacy overlay with compartments defaults to Waveshare profile', (t) => {
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
  assert.deepEqual(effective.hardwareProfile, {
    adapterType: 'waveshare_modbus',
    feedbackType: 'door_closing',
  });
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

function createConfigFiles(t: TestContext): { configPath: string; overlayPath: string } {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-yaml-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const configPath = path.join(directory, 'locker-config.yml');
  const overlayPath = path.join(directory, 'runtime-overlay.json');
  fs.writeFileSync(
    configPath,
    `modbus:
  port: /dev/null
`,
  );
  return { configPath, overlayPath };
}
