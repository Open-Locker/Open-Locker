import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';

test('matches the backend hardware-profile config hash golden vector', () => {
  const hash = computeAppliedConfigHash({
    adapter_type: 'rs485_lock_board',
    channel_count: 12,
    feedback_type: 'door_opening',
    compartments: [
      { compartment_number: 2, slaveId: 2, address: 11 },
      { compartment_number: 1, slaveId: 2, address: 0 },
    ],
  });

  assert.equal(hash, '041f1edf0ee6921b6727d250a966da978beb0af11c6b6817dfd11a083a0e0c68');
});
