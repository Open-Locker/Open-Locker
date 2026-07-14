import assert from 'node:assert/strict';
import { test } from 'node:test';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';

test('InboundProtocolGuard rejects missing message_id', () => {
  const guard = new InboundProtocolGuard(new InMemoryDedupStore());
  assert.deepEqual(guard.allow({ action: 'open_compartment', transaction_id: 'tx-1' }), {
    ok: false,
    reason: 'missing_message_id',
  });
});

test('InboundProtocolGuard rejects missing transaction_id when required', () => {
  const guard = new InboundProtocolGuard(new InMemoryDedupStore());
  assert.deepEqual(guard.allow({ action: 'open_compartment', message_id: 'msg-1' }), {
    ok: false,
    reason: 'missing_transaction_id',
  });
});

test('InboundProtocolGuard rejects duplicate message_id', () => {
  const dedup = new InMemoryDedupStore();
  const guard = new InboundProtocolGuard(dedup);
  const payload = {
    action: 'open_compartment',
    message_id: 'msg-dup',
    transaction_id: 'tx-1',
  };

  assert.deepEqual(guard.allow(payload), { ok: true });
  assert.deepEqual(guard.allow(payload), {
    ok: false,
    reason: 'duplicate_message_id',
  });
});

test('InboundProtocolGuard allows duplicate message_id when blocking disabled', () => {
  const dedup = new InMemoryDedupStore();
  const guard = new InboundProtocolGuard(dedup);
  const payload = {
    action: 'snapshot',
    message_id: 'msg-retained',
  };

  assert.deepEqual(
    guard.allow(payload, {
      requiresTransactionId: false,
      blockDuplicateMessageIds: false,
    }),
    { ok: true },
  );
  assert.deepEqual(
    guard.allow(payload, {
      requiresTransactionId: false,
      blockDuplicateMessageIds: false,
    }),
    { ok: true },
  );
});
