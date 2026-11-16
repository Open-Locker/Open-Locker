import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deriveConfiguredBoardAddresses } from '../../src/domain/config';

test('deriveConfiguredBoardAddresses returns empty list when runtime mapping is missing', () => {
  assert.deepEqual(deriveConfiguredBoardAddresses(undefined), []);
});

test('deriveConfiguredBoardAddresses returns empty list for explicit empty mapping', () => {
  assert.deepEqual(deriveConfiguredBoardAddresses([]), []);
});

test('deriveConfiguredBoardAddresses returns unique board addresses from runtime mapping', () => {
  assert.deepEqual(
    deriveConfiguredBoardAddresses([
      { compartment_number: 1, slaveId: 1, address: 0 },
      { compartment_number: 2, slaveId: 2, address: 1 },
      { compartment_number: 3, slaveId: 1, address: 2 },
    ]),
    [1, 2],
  );
});
