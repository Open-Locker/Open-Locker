import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import {
  EphemeralCredentialCache,
  SimulatorCredentialCache,
} from '../../src/adapters/simulator/simulator-credential-cache';

function withTempDir<T>(run: (directory: string) => T): T {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'sim-cred-cache-'));

  try {
    return run(directory);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}

test('returns null when nothing has been cached', () => {
  withTempDir((directory) => {
    const cache = new SimulatorCredentialCache(path.join(directory, 'creds.json'));

    assert.equal(cache.get('token-a'), null);
  });
});

test('credentials survive a new cache instance, so a re-run skips provisioning', () => {
  withTempDir((directory) => {
    const filePath = path.join(directory, 'creds.json');

    new SimulatorCredentialCache(filePath).set('token-a', {
      username: 'uuid-a',
      password: 'secret-a',
    });

    // A fresh instance stands in for the next `pnpm sim` run.
    assert.deepEqual(new SimulatorCredentialCache(filePath).get('token-a'), {
      username: 'uuid-a',
      password: 'secret-a',
    });
  });
});

test('keeps entries for several banks side by side', () => {
  withTempDir((directory) => {
    const cache = new SimulatorCredentialCache(path.join(directory, 'creds.json'));

    cache.set('token-a', { username: 'uuid-a', password: 'secret-a' });
    cache.set('token-b', { username: 'uuid-b', password: 'secret-b' });

    assert.equal(cache.get('token-a')?.username, 'uuid-a');
    assert.equal(cache.get('token-b')?.username, 'uuid-b');
  });
});

test('is written owner-only, since it holds real broker credentials', () => {
  withTempDir((directory) => {
    const filePath = path.join(directory, 'creds.json');
    new SimulatorCredentialCache(filePath).set('token-a', {
      username: 'uuid-a',
      password: 'secret-a',
    });

    assert.equal(fs.statSync(filePath).mode & 0o777, 0o600);
  });
});

test('a corrupt cache costs a re-provision attempt rather than a crash', () => {
  withTempDir((directory) => {
    const filePath = path.join(directory, 'creds.json');
    fs.writeFileSync(filePath, '{not json', 'utf8');

    assert.equal(new SimulatorCredentialCache(filePath).get('token-a'), null);
  });
});

test('treats blank credentials as absent', () => {
  withTempDir((directory) => {
    const filePath = path.join(directory, 'creds.json');
    fs.writeFileSync(filePath, JSON.stringify({ 'token-a': { username: '  ', password: '' } }));

    assert.equal(new SimulatorCredentialCache(filePath).get('token-a'), null);
  });
});

test('the cache lands beside the scenario file, never under DATA_DIR', () => {
  const previous = process.env.SIMULATOR_CREDENTIALS_FILE;
  delete process.env.SIMULATOR_CREDENTIALS_FILE;

  try {
    const cache = SimulatorCredentialCache.forScenario('/tmp/scenarios/my-scenario.yml');

    assert.equal(cache.location, path.join('/tmp/scenarios', '.simulator-credentials.json'));
  } finally {
    if (previous === undefined) {
      delete process.env.SIMULATOR_CREDENTIALS_FILE;
    } else {
      process.env.SIMULATOR_CREDENTIALS_FILE = previous;
    }
  }
});

test('SIMULATOR_CREDENTIALS_FILE overrides the default location', () => {
  const previous = process.env.SIMULATOR_CREDENTIALS_FILE;
  process.env.SIMULATOR_CREDENTIALS_FILE = '/tmp/elsewhere/creds.json';

  try {
    assert.equal(
      SimulatorCredentialCache.forScenario('/tmp/scenarios/my-scenario.yml').location,
      '/tmp/elsewhere/creds.json',
    );
  } finally {
    if (previous === undefined) {
      delete process.env.SIMULATOR_CREDENTIALS_FILE;
    } else {
      process.env.SIMULATOR_CREDENTIALS_FILE = previous;
    }
  }
});

test('the ephemeral cache keeps nothing between runs', () => {
  const cache = new EphemeralCredentialCache();
  cache.set('token-a', { username: 'uuid-a', password: 'secret-a' });

  assert.equal(cache.get('token-a')?.username, 'uuid-a');
  assert.equal(new EphemeralCredentialCache().get('token-a'), null);
});
