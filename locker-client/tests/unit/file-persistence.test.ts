import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import {
  atomicWriteFileSync,
  ensurePrivateDirectory,
  readPrivateFileSync,
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

test(
  'ensurePrivateDirectory hardens an existing data directory',
  { skip: !supportsUnixModes },
  (t) => {
    const root = createTemporaryDirectory(t);
    const dataDirectory = path.join(root, 'data');
    fs.mkdirSync(dataDirectory, { mode: 0o755 });
    fs.chmodSync(dataDirectory, 0o755);

    ensurePrivateDirectory(dataDirectory);

    assert.equal(fs.statSync(dataDirectory).mode & 0o777, 0o700);
  },
);

test('ensurePrivateDirectory refuses symbolic links', { skip: !supportsUnixModes }, (t) => {
  const root = createTemporaryDirectory(t);
  const target = path.join(root, 'target');
  const link = path.join(root, 'data');
  fs.mkdirSync(target);
  fs.symlinkSync(target, link);

  assert.throws(() => ensurePrivateDirectory(link));
});

test('readPrivateFileSync hardens and reads the opened file', { skip: !supportsUnixModes }, (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  fs.writeFileSync(file, 'private state', { mode: 0o644 });
  fs.chmodSync(file, 0o644);

  assert.equal(readPrivateFileSync(file).toString('utf8'), 'private state');
  assert.equal(fs.statSync(file).mode & 0o777, 0o600);
});

test('readPrivateFileSync refuses symbolic links', { skip: !supportsUnixModes }, (t) => {
  const directory = createTemporaryDirectory(t);
  const target = path.join(directory, 'target.json');
  const link = path.join(directory, 'state.json');
  fs.writeFileSync(target, 'private state', 'utf8');
  fs.symlinkSync(target, link);

  assert.throws(() => readPrivateFileSync(link));
});

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

test('atomicWriteFileSync tolerates an unsupported directory fsync', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  let fsyncCalls = 0;
  const fileSystem: AtomicWriteFileSystem = {
    ...fs,
    fsyncSync(descriptor) {
      fsyncCalls += 1;
      if (fsyncCalls === 2) {
        throw nodeError('directory fsync unsupported', 'EINVAL');
      }
      fs.fsyncSync(descriptor);
    },
  };

  assert.doesNotThrow(() => atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 }));
  assert.equal(fs.readFileSync(file, 'utf8'), 'new state');
});

test('atomicWriteFileSync propagates a directory storage error after rename', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  let fsyncCalls = 0;
  const storageError = nodeError('simulated storage failure', 'EIO');
  const fileSystem: AtomicWriteFileSystem = {
    ...fs,
    fsyncSync(descriptor) {
      fsyncCalls += 1;
      if (fsyncCalls === 2) {
        throw storageError;
      }
      fs.fsyncSync(descriptor);
    },
  };

  assert.throws(
    () => atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 }),
    (error: unknown) =>
      error instanceof Error &&
      error.message.includes('rename completed') &&
      error.cause === storageError,
  );
  assert.equal(fs.readFileSync(file, 'utf8'), 'new state');
});

test('atomicWriteFileSync propagates a directory close error after rename', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  let closeCalls = 0;
  const storageError = nodeError('simulated close failure', 'EIO');
  const fileSystem: AtomicWriteFileSystem = {
    ...fs,
    closeSync(descriptor) {
      closeCalls += 1;
      if (closeCalls === 3) {
        throw storageError;
      }
      fs.closeSync(descriptor);
    },
  };

  assert.throws(
    () => atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 }),
    (error: unknown) =>
      error instanceof Error &&
      error.message.includes('rename completed') &&
      error.cause === storageError,
  );
  assert.equal(fs.readFileSync(file, 'utf8'), 'new state');
});

test('atomicWriteFileSync preserves write and cleanup failures', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  const writeError = new Error('simulated rename failure');
  const cleanupError = new Error('simulated cleanup failure');
  const fileSystem: AtomicWriteFileSystem = {
    ...fs,
    renameSync() {
      throw writeError;
    },
    unlinkSync() {
      throw cleanupError;
    },
  };

  assert.throws(
    () => atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 }),
    (error: unknown) =>
      error instanceof AggregateError &&
      error.cause === writeError &&
      error.errors[0] === writeError &&
      error.errors[1] === cleanupError,
  );
});

test('atomicWriteFileSync fails instead of looping when a write makes no progress', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  const fileSystem: AtomicWriteFileSystem = {
    ...fs,
    writeSync() {
      return 0;
    },
  };

  assert.throws(
    () => atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 }),
    /Unable to complete persistent write/,
  );
  assert.deepEqual(fs.readdirSync(directory), []);
});

test('atomicWriteFileSync completes partial writes', (t) => {
  const directory = createTemporaryDirectory(t);
  const file = path.join(directory, 'state.json');
  const fileSystem = {
    ...fs,
    writeSync(
      descriptor: number,
      buffer: NodeJS.ArrayBufferView,
      offset: number,
      length: number,
      position: number | null,
    ) {
      return fs.writeSync(descriptor, buffer, offset, Math.min(length, 2), position);
    },
  } as unknown as AtomicWriteFileSystem;

  atomicWriteFileSync(file, 'new state', { fileSystem, mode: 0o600 });

  assert.equal(fs.readFileSync(file, 'utf8'), 'new state');
});

function nodeError(message: string, code: string): NodeJS.ErrnoException {
  return Object.assign(new Error(message), { code });
}

function createTemporaryDirectory(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-persistence-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return directory;
}
