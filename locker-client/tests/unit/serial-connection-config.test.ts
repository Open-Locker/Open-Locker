import assert from 'node:assert/strict';
import { test } from 'node:test';
import { serialConnectionFromModbus } from '../../src/adapters/serial/serial-connection-config';

test('serialConnectionFromModbus defaults timeout to 1000 ms', () => {
  assert.equal(serialConnectionFromModbus({ port: '/dev/null' }).timeout, 1000);
});
