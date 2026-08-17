import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { FileCredentialStore } from '../../src/adapters/persistence/file-credential.store';
import { PersistentStateCorruptedError } from '../../src/infrastructure/file-persistence';

const supportsUnixModes = process.platform !== 'win32';

test(
  'new credentials are persisted with owner-only permissions',
  { skip: !supportsUnixModes },
  (t) => {
    const file = createCredentialFile(t);
    const store = new FileCredentialStore(file);

    store.saveCredentials({ username: 'locker-user', password: 'test-password' });

    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
  },
);

test(
  'existing credentials with broad permissions are hardened on read',
  { skip: !supportsUnixModes },
  (t) => {
    const file = createCredentialFile(t);
    fs.writeFileSync(file, JSON.stringify({ username: 'locker-user', password: 'test-password' }), {
      mode: 0o644,
    });
    fs.chmodSync(file, 0o644);

    const credentials = new FileCredentialStore(file).getCredentials();

    assert.deepEqual(credentials, { username: 'locker-user', password: 'test-password' });
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
  },
);

test('corrupt credentials fail closed and remain untouched', (t) => {
  const file = createCredentialFile(t);
  const corruptContents = '{"username":"locker-user"';
  fs.writeFileSync(file, corruptContents, 'utf8');

  assert.throws(
    () => new FileCredentialStore(file).isProvisioned(),
    (error: unknown) =>
      error instanceof PersistentStateCorruptedError &&
      error.stateType === 'MQTT credentials' &&
      !error.message.includes(corruptContents),
  );
  assert.equal(fs.readFileSync(file, 'utf8'), corruptContents);
});

function createCredentialFile(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-credentials-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return path.join(directory, 'credentials.json');
}
