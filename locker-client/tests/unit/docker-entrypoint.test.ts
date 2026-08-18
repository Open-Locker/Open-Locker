import assert from 'node:assert/strict';
import { type ChildProcess, spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';

const entrypoint = path.resolve(__dirname, '../../../docker-entrypoint.sh');
const supportsFlock =
  process.platform === 'linux' &&
  spawnSync('flock', ['--version'], { stdio: 'ignore' }).status === 0;

test(
  'entrypoint excludes a second writer and releases the lock after a crash',
  { skip: !supportsFlock },
  async (t) => {
    const root = createTemporaryDirectory(t);
    const dataDirectory = path.join(root, 'data');
    const readyFile = path.join(root, 'ready');
    const environment = { ...process.env, DATA_DIR: dataDirectory };
    const holder = spawn(
      'sh',
      [
        entrypoint,
        process.execPath,
        '-e',
        "require('node:fs').writeFileSync(process.argv[1], 'ready'); setInterval(() => {}, 1000)",
        readyFile,
      ],
      { env: environment, stdio: 'ignore' },
    );
    t.after(() => {
      if (holder.exitCode === null && holder.signalCode === null) {
        holder.kill('SIGKILL');
      }
    });

    await waitForFileOrExit(readyFile, holder);

    const contender = spawnSync('sh', [entrypoint, process.execPath, '-e', 'process.exit(0)'], {
      env: environment,
      stdio: 'ignore',
    });

    assert.equal(contender.status, 75);
    assert.equal(fs.statSync(dataDirectory).mode & 0o777, 0o700);
    assert.equal(fs.statSync(path.join(dataDirectory, '.locker-client.lock')).mode & 0o777, 0o600);

    holder.kill('SIGKILL');
    await waitForExit(holder);

    assert.equal(fs.existsSync(path.join(dataDirectory, '.locker-client.lock')), true);
    const successor = spawnSync('sh', [entrypoint, process.execPath, '-e', 'process.exit(0)'], {
      env: environment,
      stdio: 'ignore',
    });
    assert.equal(successor.status, 0);
  },
);

function createTemporaryDirectory(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-entrypoint-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return directory;
}

async function waitForFileOrExit(file: string, child: ChildProcess): Promise<void> {
  const deadline = Date.now() + 5_000;
  while (!fs.existsSync(file)) {
    if (child.exitCode !== null || child.signalCode !== null) {
      throw new Error(`lock holder exited before becoming ready`);
    }
    if (Date.now() >= deadline) {
      throw new Error(`timed out waiting for lock holder`);
    }
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}

async function waitForExit(child: ChildProcess): Promise<void> {
  if (child.exitCode !== null || child.signalCode !== null) {
    return;
  }
  await new Promise<void>((resolve) => child.once('exit', () => resolve()));
}
