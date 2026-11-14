import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lockBoardSerialConnection } from '../../src/adapters/serial/serial-connection-config';

test('lockBoardSerialConnection fixes the board firmware framing at 9600 8N1', () => {
  assert.deepEqual(lockBoardSerialConnection('/dev/serial/by-id/usb-rs485'), {
    port: '/dev/serial/by-id/usb-rs485',
    baudRate: 9600,
    dataBits: 8,
    stopBits: 1,
    parity: 'none',
  });
});
