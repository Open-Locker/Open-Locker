import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  isReconnectableModbusError,
  LockerError,
  mapErrorToMqttCode,
  ModbusTransportError,
  MqttErrorCode,
} from '../../src/domain/errors';

test('an error we raise reports the code it was given', () => {
  assert.equal(
    mapErrorToMqttCode(new LockerError(MqttErrorCode.DOOR_JAMMED, 'anything at all')),
    MqttErrorCode.DOOR_JAMMED,
  );
  assert.equal(
    mapErrorToMqttCode(new ModbusTransportError('Port Not Open', true)),
    MqttErrorCode.MODBUS_ERROR,
  );
});

test('rewording a message no longer changes the code reported', () => {
  // Previously any message containing "jammed" mapped to DOOR_JAMMED, so an
  // unrelated error could be reported to the backend as a stuck door.
  const reworded = new Error('the printer jammed while not found in config');

  assert.equal(mapErrorToMqttCode(reworded), MqttErrorCode.UNKNOWN_ERROR);
});

test('third-party modbus transport failures are still recognised', () => {
  // modbus-serial throws plain Errors, so message text is the only signal.
  for (const message of ['Port Not Open', 'connect ECONNREFUSED', 'Timed out', 'CRC error']) {
    assert.equal(
      mapErrorToMqttCode(new Error(message)),
      MqttErrorCode.MODBUS_ERROR,
      `expected "${message}" to map to MODBUS_ERROR`,
    );
  }
});

test('a non-error value maps to unknown rather than throwing', () => {
  assert.equal(mapErrorToMqttCode('a bare string'), MqttErrorCode.UNKNOWN_ERROR);
  assert.equal(mapErrorToMqttCode(undefined), MqttErrorCode.UNKNOWN_ERROR);
});

test('only transport faults that reconnecting can clear are reconnectable', () => {
  assert.equal(isReconnectableModbusError(new ModbusTransportError('Port Not Open', true)), true);
  // A missing library API is a packaging fault; redialling the port cannot fix it.
  assert.equal(
    isReconnectableModbusError(
      new ModbusTransportError('modbus-serial customFunction API is unavailable'),
    ),
    false,
  );
  // Real socket failures carry the code; the wording is incidental.
  assert.equal(
    isReconnectableModbusError(withCode(new Error('connect failed'), 'ECONNREFUSED')),
    true,
  );
  assert.equal(isReconnectableModbusError(new Error('something unrelated')), false);
});

/** Attaches a `code` the way the runtime does, so tests match real error shapes. */
function withCode(error: Error, code: string, cause?: unknown): Error {
  Object.assign(error, { code, ...(cause === undefined ? {} : { cause }) });

  return error;
}

test('a serial port that went away is reconnectable, by code rather than wording', () => {
  // What an unplugged USB adapter actually reports. None of these messages contain
  // the strings the old classification looked for, so the most recoverable failure
  // there is used to be treated as permanent.
  for (const code of ['ENOENT', 'ENXIO', 'EIO', 'EBADF']) {
    assert.equal(
      isReconnectableModbusError(withCode(new Error('no such file or directory'), code)),
      true,
      `expected ${code} to be reconnectable`,
    );
  }
});

test('a permissions fault is not reconnectable', () => {
  // EACCES means the container user may not open the device — a missing dialout
  // group, say. It fails identically every time, so retrying only produces noise
  // until a human changes something.
  assert.equal(
    isReconnectableModbusError(withCode(new Error('permission denied'), 'EACCES')),
    false,
  );
});

test('a code carried on a nested cause is still found', () => {
  // serialport wraps in some paths, and a code we cannot reach classifies as
  // unknown — which, with no catch-all, means no reconnect at all.
  const wrapped = new Error('failed to open port');
  Object.assign(wrapped, { cause: withCode(new Error('device not configured'), 'ENXIO') });

  assert.equal(isReconnectableModbusError(wrapped), true);
});

test('a serial fault reports MODBUS_ERROR rather than unknown', () => {
  // The device reconnects correctly; the backend must not be told the client hit
  // something it could not identify.
  assert.equal(
    mapErrorToMqttCode(withCode(new Error('no such file or directory'), 'ENOENT')),
    MqttErrorCode.MODBUS_ERROR,
  );
  assert.equal(
    mapErrorToMqttCode(withCode(new Error('nope'), 'ESOMETHINGELSE')),
    MqttErrorCode.UNKNOWN_ERROR,
  );
});

test('a fault that merely mentions modbus is not reported as a hardware error', () => {
  // A bare `modbus` match caught nearly every hardware-adjacent message here,
  // including our own configuration and programming faults, and sent them to the
  // backend as MODBUS_ERROR — a bug dressed as a broken bus.
  assert.equal(
    mapErrorToMqttCode(new Error('Invalid modbus register mapping for compartment 3')),
    MqttErrorCode.UNKNOWN_ERROR,
  );
  assert.equal(
    mapErrorToMqttCode(new Error('modbus config missing slaveId')),
    MqttErrorCode.UNKNOWN_ERROR,
  );
});

test('the specific library transport failures are still reported as MODBUS_ERROR', () => {
  for (const message of ['Port Not Open', 'connect ECONNREFUSED', 'Timed out', 'CRC error']) {
    assert.equal(mapErrorToMqttCode(new Error(message)), MqttErrorCode.MODBUS_ERROR, message);
  }
});
