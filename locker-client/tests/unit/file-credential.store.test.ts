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

    store.saveCredentials({
      username: 'locker-user',
      password: 'test-password',
      lockerUuid: 'locker-uuid',
    });

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

    assert.deepEqual(credentials, {
      username: 'locker-user',
      password: 'test-password',
      lockerUuid: 'locker-user',
    });
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

test('a credentials file written before per-provisioning identities still loads', (t) => {
  // Back then the username was the locker uuid, so an absent lockerUuid means
  // "the username is the uuid" — an existing install must not need rewriting.
  const file = createCredentialFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      username: '019e5a20-8a02-718d-9146-a8a656edabbd',
      password: 'test-password',
    }),
    { mode: 0o600 },
  );

  const credentials = new FileCredentialStore(file).getCredentials();

  assert.deepEqual(credentials, {
    username: '019e5a20-8a02-718d-9146-a8a656edabbd',
    password: 'test-password',
    lockerUuid: '019e5a20-8a02-718d-9146-a8a656edabbd',
  });
});

test('an opaque username and its locker uuid round-trip separately', (t) => {
  const file = createCredentialFile(t);
  const store = new FileCredentialStore(file);

  store.saveCredentials({
    username: 'hzv2uqMeZ0iMThq2ZCqM5uefe9OqoWtb',
    password: 'test-password',
    lockerUuid: '019e5a20-8a02-718d-9146-a8a656edabbd',
  });

  const credentials = new FileCredentialStore(file).getCredentials();

  assert.equal(credentials?.username, 'hzv2uqMeZ0iMThq2ZCqM5uefe9OqoWtb');
  assert.equal(credentials?.lockerUuid, '019e5a20-8a02-718d-9146-a8a656edabbd');
  assert.notEqual(credentials?.username, credentials?.lockerUuid);
});
