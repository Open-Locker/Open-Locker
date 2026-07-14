import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deriveConfiguredSlaveIds } from '../../src/domain/config';

test('deriveConfiguredSlaveIds returns empty list when runtime mapping is missing', () => {
  assert.deepEqual(deriveConfiguredSlaveIds(undefined), []);
});

test('deriveConfiguredSlaveIds returns empty list for explicit empty mapping', () => {
  assert.deepEqual(deriveConfiguredSlaveIds([]), []);
});

test('deriveConfiguredSlaveIds returns unique slave ids from runtime mapping', () => {
  assert.deepEqual(
    deriveConfiguredSlaveIds([
      { compartment_number: 1, slaveId: 1, address: 0 },
      { compartment_number: 2, slaveId: 2, address: 1 },
      { compartment_number: 3, slaveId: 1, address: 2 },
    ]),
    [1, 2],
  );
});
