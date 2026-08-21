import assert from 'node:assert/strict';
import { test } from 'node:test';
import { closeOrAbandon, dispatchMqttMessage } from '../../src/bootstrap/createApp';

const silentLog = { error: () => undefined };

test('dispatchMqttMessage returns a promise callers can await', async () => {
  let finished = false;
  const dispatcher = {
    async dispatch(): Promise<void> {
      await new Promise((resolve) => setTimeout(resolve, 10));
      finished = true;
    },
  };

  const dispatched = dispatchMqttMessage(
    dispatcher,
    'locker/test/command',
    'locker/test/command',
    Buffer.from('{}'),
  );

  assert.equal(finished, false, 'work should still be running before the await');
  await dispatched;
  assert.equal(finished, true, 'awaiting the returned promise waits for the command');
});

test('a settled dispatch never rejects, so shutdown can await it safely', async () => {
  const dispatcher = {
    async dispatch(): Promise<void> {
      throw new Error('dispatch exploded');
    },
  };

  await dispatchMqttMessage(
    dispatcher,
    'locker/test/command',
    'locker/test/command',
    Buffer.from('{}'),
    silentLog,
  );
});

test('messages on other topics resolve immediately without dispatching', async () => {
  let dispatched = false;
  const dispatcher = {
    async dispatch(): Promise<void> {
      dispatched = true;
    },
  };

  await dispatchMqttMessage(
    dispatcher,
    'locker/test/command',
    'locker/test/state/heartbeat',
    Buffer.from('{}'),
  );

  assert.equal(dispatched, false);
});

test('closeOrAbandon waits for a step that closes normally', async () => {
  let closed = false;

  await closeOrAbandon(
    'normal',
    async () => {
      await new Promise((resolve) => setTimeout(resolve, 5));
      closed = true;
    },
    1_000,
    silentLog,
  );

  assert.equal(closed, true);
});

test('a step that never resolves is abandoned instead of hanging shutdown', async () => {
  const logged: Array<{ message: string; metadata?: Record<string, unknown> }> = [];
  const log = {
    error(message: string, metadata?: Record<string, unknown>) {
      logged.push({ message, metadata });
    },
  };

  // The wedged-serial-port case: disconnect() never settles.
  await closeOrAbandon('modbus-disconnect', () => new Promise<void>(() => {}), 20, log);

  assert.equal(logged.length, 1);
  assert.equal(logged[0].message, 'Shutdown step did not finish; continuing without it');
  assert.deepEqual(logged[0].metadata, { step: 'modbus-disconnect', timeoutMs: 20 });
});

test('a step that throws is logged and the sequence continues', async () => {
  const logged: Array<{ message: string; metadata?: Record<string, unknown> }> = [];
  const log = {
    error(message: string, metadata?: Record<string, unknown>) {
      logged.push({ message, metadata });
    },
  };

  await closeOrAbandon(
    'transport-disconnect',
    async () => {
      throw new Error('socket already destroyed');
    },
    1_000,
    log,
  );

  assert.equal(logged.length, 1);
  assert.equal(logged[0].message, 'Shutdown step failed; continuing');
  assert.deepEqual(logged[0].metadata, {
    step: 'transport-disconnect',
    error: 'socket already destroyed',
  });
});
