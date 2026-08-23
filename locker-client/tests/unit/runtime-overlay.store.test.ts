import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { FileRuntimeOverlayStore } from '../../src/adapters/config/runtime-overlay.store';
import { PersistentStateCorruptedError } from '../../src/infrastructure/file-persistence';

const supportsUnixModes = process.platform !== 'win32';

test(
  'existing runtime overlay permissions are hardened on read',
  { skip: !supportsUnixModes },
  (t) => {
    const file = createOverlayFile(t);
    fs.writeFileSync(file, JSON.stringify({ mqtt: { heartbeatInterval: 30 } }), { mode: 0o644 });
    fs.chmodSync(file, 0o644);

    assert.deepEqual(new FileRuntimeOverlayStore(file).load(), {
      mqtt: { heartbeatInterval: 30 },
    });
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
  },
);

test('empty runtime overlay fails closed and is not replaced', (t) => {
  const file = createOverlayFile(t);
  fs.writeFileSync(file, '', 'utf8');

  assert.throws(
    () => new FileRuntimeOverlayStore(file).load(),
    (error: unknown) =>
      error instanceof PersistentStateCorruptedError &&
      error.stateType === 'runtime configuration overlay',
  );
  assert.equal(fs.readFileSync(file, 'utf8'), '');
});

test('malformed runtime overlay fails closed and is not replaced', (t) => {
  const file = createOverlayFile(t);
  const corruptContents = '{"compartments":';
  fs.writeFileSync(file, corruptContents, 'utf8');

  assert.throws(
    () => new FileRuntimeOverlayStore(file).load(),
    (error: unknown) => error instanceof PersistentStateCorruptedError,
  );
  assert.equal(fs.readFileSync(file, 'utf8'), corruptContents);
});

test('runtime overlay persists and sanitizes the hardware profile', (t) => {
  const file = createOverlayFile(t);
  const store = new FileRuntimeOverlayStore(file);
  const saved = store.save({
    hardwareProfile: {
      adapterType: 'rs485_lock_board',
      channelCount: 50,
      feedbackType: 'door_opening',
    },
    compartments: [{ compartment_number: 1, slaveId: 31, address: 49 }],
    appliedConfigHash: 'a'.repeat(64),
  });

  assert.deepEqual(store.load(), saved);
});

test('runtime overlay rejects unsupported variants and out-of-range channels', (t) => {
  const file = createOverlayFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      hardwareProfile: {
        adapterType: 'rs485_lock_board',
        channelCount: 10,
        feedbackType: 'door_closing',
      },
    }),
  );
  assert.throws(() => new FileRuntimeOverlayStore(file).load(), PersistentStateCorruptedError);
});

function createOverlayFile(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-overlay-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return path.join(directory, 'runtime-overlay.json');
}
