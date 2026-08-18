import assert from 'node:assert/strict';
import { test } from 'node:test';
import { redactTopic } from '../../src/infrastructure/redact-topic';

test('registration topics redact the complete plaintext token', () => {
  const token = 'one-time-provisioning-token';

  const redacted = redactTopic(`locker/register/${token}`);

  assert.equal(redacted, 'locker/register/[redacted]');
  assert.equal(redacted.includes(token), false);
});

test('non-registration topics remain unchanged', () => {
  const topic = 'locker/example/state/heartbeat';

  assert.equal(redactTopic(topic), topic);
});
