import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';

test('matches the backend hardware-profile config hash golden vector', () => {
  const hash = computeAppliedConfigHash({
    adapter_type: 'rs485_lock_board',
    feedback_type: 'door_opening',
    compartments: [
      { compartment_number: 2, slaveId: 2, address: 11 },
      { compartment_number: 1, slaveId: 2, address: 0 },
    ],
  });

  assert.equal(hash, 'deac8a5b4aea15d097074e3c092d2632c3baa3d0adb0e91c96a13f745dd30b9e');
});
