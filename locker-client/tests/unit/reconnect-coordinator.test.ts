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

test('a spent budget does not outlive the outage that spent it', async () => {
  // The bug this replaces: attempts reset only on a successful connect, so five
  // failures left the bus unusable until the process restarted — however long the
  // adapter had been back.
  const coordinator = new ReconnectCoordinator({ maxAttempts: 2, delayMs: 1, cooldownMs: 20 });
  let dialsBeforeRecovery = 0;

  await assert.rejects(
    coordinator.run(async () => {
      dialsBeforeRecovery++;
      throw new Error('adapter unplugged');
    }),
  );
  assert.equal(dialsBeforeRecovery, 2, 'the cycle spends its budget');
  assert.equal(coordinator.isCycleSpent(), true);

  // Immediately afterwards the budget is still spent: this is the "declared dead"
  // window, and it must not dial.
  let dialedDuringCooldown = false;
  await assert.rejects(
    coordinator.run(async () => {
      dialedDuringCooldown = true;
    }),
  );
  assert.equal(dialedDuringCooldown, false, 'no dialling until the cooldown passes');

  await new Promise((resolve) => setTimeout(resolve, 25));

  let recovered = false;
  await coordinator.run(async () => {
    recovered = true;
  });

  assert.equal(recovered, true, 'a new cycle starts once the cooldown has passed');
  assert.equal(coordinator.isCycleSpent(), false);
  assert.equal(coordinator.getAttempts(), 0);
});

test('giving up is logged once per cycle, not once per refusal', async () => {
  // A bus down for an hour must not produce an hour of identical error lines, or
  // the one that matters is never noticed.
  const errors: string[] = [];
  const warns: string[] = [];
  const coordinator = new ReconnectCoordinator(
    { maxAttempts: 2, delayMs: 1, cooldownMs: 10_000 },
    {
      warn: (message: string) => warns.push(message),
      error: (message: string) => errors.push(message),
    },
  );

  const fail = async (): Promise<void> => {
    throw new Error('adapter unplugged');
  };

  await assert.rejects(coordinator.run(fail));
  for (let i = 0; i < 5; i++) {
    await assert.rejects(coordinator.run(fail));
  }

  assert.equal(errors.length, 1, 'one error for the cycle, not one per refusal');
  assert.equal(warns.length, 1, 'the retry within the cycle still warns');
});

test('a successful connect clears the spent marker', async () => {
  const coordinator = new ReconnectCoordinator({ maxAttempts: 1, delayMs: 1, cooldownMs: 5 });

  await assert.rejects(
    coordinator.run(async () => {
      throw new Error('adapter unplugged');
    }),
  );
  assert.equal(coordinator.isCycleSpent(), true);

  await new Promise((resolve) => setTimeout(resolve, 10));
  await coordinator.run(async () => {});

  assert.equal(coordinator.isCycleSpent(), false);
  assert.equal(coordinator.getAttempts(), 0);
});
