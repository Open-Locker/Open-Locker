import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import {
  atomicWriteFileSync,
  ensurePrivateDirectory,
  type AtomicWriteFileSystem,
} from '../../src/infrastructure/file-persistence';

const supportsUnixModes = process.platform !== 'win32';

test(
  'ensurePrivateDirectory creates a private data directory',
  { skip: !supportsUnixModes },
  (t) => {
    const root = createTemporaryDirectory(t);
    const dataDirectory = path.join(root, 'data');

    ensurePrivateDirectory(dataDirectory);

    assert.equal(fs.statSync(dataDirectory).mode & 0o777, 0o700);
  },
);

test('atomicWriteFileSync replaces an existing file and applies its mode', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  fs.writeFileSync(file, 'old state', 'utf8');

  atomicWriteFileSync(file, 'new state', { mode: 0o600 });

  assert.equal(fs.readFileSync(file, 'utf8'), 'new state');
  if (supportsUnixModes) {
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
  }
});

test('atomic write failure before rename preserves the old target and removes temp files', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  fs.writeFileSync(file, 'old state', 'utf8');
  const fileSystem: AtomicWriteFileSystem = {
    ...fs,
    renameSync() {
      throw new Error('simulated rename failure');
    },
  };

  assert.throws(
    () => atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 }),
    /simulated rename failure/,
  );

  assert.equal(fs.readFileSync(file, 'utf8'), 'old state');
  assert.deepEqual(fs.readdirSync(directory), ['state.json']);
});

function createTemporaryDirectory(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-persistence-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return directory;
}
