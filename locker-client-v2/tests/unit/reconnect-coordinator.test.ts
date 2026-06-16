import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ReconnectCoordinator } from '../../src/adapters/modbus/reconnect-coordinator';

test('ReconnectCoordinator retries until connect succeeds', async () => {
  const coordinator = new ReconnectCoordinator({
    maxAttempts: 0,
    delayMs: 10,
  });
  let attempts = 0;

  await coordinator.run(async () => {
    attempts++;
    if (attempts < 2) {
      throw new Error('connect failed');
    }
  });

  assert.equal(attempts, 2);
  assert.equal(coordinator.getAttempts(), 0);
});

test('ReconnectCoordinator respects maxAttempts', async () => {
  const coordinator = new ReconnectCoordinator({
    maxAttempts: 2,
    delayMs: 10,
  });
  let attempts = 0;

  await assert.rejects(
    () =>
      coordinator.run(async () => {
        attempts++;
        throw new Error('connect failed');
      }),
    /connect failed/,
  );

  assert.equal(attempts, 2);
});

test('ReconnectCoordinator deduplicates concurrent reconnect calls', async () => {
  const coordinator = new ReconnectCoordinator({ delayMs: 10 });
  let attempts = 0;

  await Promise.all([
    coordinator.run(async () => {
      attempts++;
      await delay(20);
    }),
    coordinator.run(async () => {
      attempts++;
      await delay(20);
    }),
  ]);

  assert.equal(attempts, 1);
});

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
