import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import { DEFAULT_SIMULATOR_MQTT_BROKER_URL } from '../../src/bootstrap/createSimulatorApp';

/**
 * The simulator is only a faithful stand-in while it dispatches the same
 * commands as the real client. A handler added to one root and forgotten in the
 * other makes the simulator quietly answer `UNKNOWN_ACTION` for something
 * production handles — the drift ADR-0031 exists to prevent.
 *
 * Checked textually rather than by instantiating both roots: `createApp` needs a
 * config file, a serial device and a broker, none of which belong in a unit
 * test. The two roots also diverge on purpose elsewhere — the simulator has no
 * `recoverInterruptedCommands` or flush wiring, because its dedup store is
 * in-memory — so this asserts the one property that must match, not general
 * equivalence.
 */
/**
 * Walks up from this file to the directory holding package.json.
 *
 * Neither `__dirname` nor `process.cwd()` works on its own: compiled tests run
 * from `dist/`, where the TypeScript sources do not exist, and cwd is only the
 * package root when the runner is invoked from there.
 */
function packageRoot(): string {
  let dir = __dirname;

  while (!fs.existsSync(path.join(dir, 'package.json'))) {
    const parent = path.dirname(dir);

    if (parent === dir) {
      throw new Error('Could not locate the locker-client package root');
    }

    dir = parent;
  }

  return dir;
}

function registeredHandlers(file: string): string[] {
  const source = fs.readFileSync(path.join(packageRoot(), 'src', 'bootstrap', file), 'utf8');

  return [...source.matchAll(/dispatcher\.register\(\s*(create\w+Handler)/g)]
    .map((match) => match[1])
    .toSorted();
}

test('both composition roots register the same command handlers', () => {
  const production = registeredHandlers('createApp.ts');
  const simulator = registeredHandlers('createSimulatorApp.ts');

  assert.ok(production.length > 0, 'the production root must register handlers');
  assert.deepEqual(
    simulator,
    production,
    'a handler registered in one composition root but not the other means the simulator no longer mirrors the client',
  );
});

test('simulator defaults to the local plaintext broker', () => {
  assert.equal(DEFAULT_SIMULATOR_MQTT_BROKER_URL, 'mqtt://localhost:1883');
});
