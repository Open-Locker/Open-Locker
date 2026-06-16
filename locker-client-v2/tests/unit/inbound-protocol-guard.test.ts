import assert from 'node:assert/strict';
import { test } from 'node:test';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';

test('InboundProtocolGuard rejects missing message_id', () => {
  const guard = new InboundProtocolGuard(new InMemoryDedupStore());
  assert.equal(guard.allow({ action: 'open_compartment', transaction_id: 'tx-1' }), false);
});

test('InboundProtocolGuard rejects missing transaction_id when required', () => {
  const guard = new InboundProtocolGuard(new InMemoryDedupStore());
  assert.equal(guard.allow({ action: 'open_compartment', message_id: 'msg-1' }), false);
});

test('InboundProtocolGuard rejects duplicate message_id', () => {
  const dedup = new InMemoryDedupStore();
  const guard = new InboundProtocolGuard(dedup);
  const payload = {
    action: 'open_compartment',
    message_id: 'msg-dup',
    transaction_id: 'tx-1',
  };

  assert.equal(guard.allow(payload), true);
  assert.equal(guard.allow(payload), false);
});

test('InboundProtocolGuard allows duplicate message_id when blocking disabled', () => {
  const dedup = new InMemoryDedupStore();
  const guard = new InboundProtocolGuard(dedup);
  const payload = {
    action: 'snapshot',
    message_id: 'msg-retained',
  };

  assert.equal(
    guard.allow(payload, {
      requiresTransactionId: false,
      blockDuplicateMessageIds: false,
    }),
    true,
  );
  assert.equal(
    guard.allow(payload, {
      requiresTransactionId: false,
      blockDuplicateMessageIds: false,
    }),
    true,
  );
});
