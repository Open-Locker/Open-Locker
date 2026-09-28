export enum MqttErrorCode {
  DOOR_JAMMED = 'DOOR_JAMMED',
  COMPARTMENT_NOT_FOUND = 'COMPARTMENT_NOT_FOUND',
  RUNTIME_CONFIG_NOT_APPLIED = 'RUNTIME_CONFIG_NOT_APPLIED',
  HARDWARE_ERROR = 'HARDWARE_ERROR',
  MODBUS_ERROR = 'MODBUS_ERROR',
  INVALID_COMMAND = 'INVALID_COMMAND',
  UNKNOWN_ACTION = 'UNKNOWN_ACTION',
  MISSING_MESSAGE_ID = 'MISSING_MESSAGE_ID',
  SHUTTING_DOWN = 'SHUTTING_DOWN',
  INVALID_CONFIG = 'INVALID_CONFIG',
  TIMEOUT = 'TIMEOUT',
  UNKNOWN_ERROR = 'UNKNOWN_ERROR',
}

export class LockerError extends Error {
  constructor(
    public readonly code: MqttErrorCode,
    message: string,
  ) {
    super(message);
    this.name = 'LockerError';
  }
}

export class HardwareTransportError extends LockerError {
  constructor(
    message: string,
    public readonly reconnectable = false,
  ) {
    super(MqttErrorCode.HARDWARE_ERROR, message);
    this.name = 'HardwareTransportError';
  }
}

/**
 * A failed unlock whose request never left the client. Safe to retry after a
 * reconnect: the lock cannot have moved.
 */
export class UnlockNotSentError extends HardwareTransportError {
  constructor(message: string, reconnectable = false) {
    super(message, reconnectable);
    this.name = 'UnlockNotSentError';
  }
}

/**
 * A failed unlock whose request may have reached the board. Never re-sent
 * automatically: the lock may already have released (ADR-0067).
 */
export class UnlockOutcomeUnknownError extends HardwareTransportError {
  constructor(message: string, reconnectable = false) {
    super(message, reconnectable);
    this.name = 'UnlockOutcomeUnknownError';
  }
}

/** A board that stayed silent for a whole response window. */
export class BoardNotRespondingError extends HardwareTransportError {
  constructor(message: string, reconnectable = true) {
    super(message, reconnectable);
    this.name = 'BoardNotRespondingError';
  }
}

/**
 * Serial faults the operating system reports by code. A removed adapter, a device
 * node that no longer exists, a handle the kernel has invalidated: all of them
 * mean the port went away and dialling again is the right response.
 *
 * Codes rather than wording, because a message is written for a human and a
 * dependency may reword it without warning, while a code is part of the interface.
 */
const RECOVERABLE_SERIAL_CODES = new Set(['ENOENT', 'ENXIO', 'EIO', 'EBADF', 'ECONNREFUSED']);

/**
 * Reads `code` from the error and from a nested `cause`: serialport wraps in some
 * paths, and a code we cannot reach classifies as unknown — which, with no
 * catch-all, means no reconnect for the most recoverable failure there is.
 */
function serialErrorCode(error: unknown): string | null {
  let current: unknown = error;

  for (let depth = 0; depth < 5 && current !== null && typeof current === 'object'; depth++) {
    const code = (current as { code?: unknown }).code;
    if (typeof code === 'string' && code !== '') {
      return code;
    }

    current = (current as { cause?: unknown }).cause ?? null;
  }

  return null;
}

function hasRecoverableSerialCode(error: unknown): boolean {
  const code = serialErrorCode(error);

  return code !== null && RECOVERABLE_SERIAL_CODES.has(code);
}

/**
 * Serial transport failures raised by a library rather than by us.
 *
 * A fault carrying a serial error code is one; otherwise message text is the
 * only signal, which is why matching it is confined to this one function:
 * everything we raise ourselves carries an explicit code instead. Unrecognised
 * errors are better reported as unknown than misattributed to the hardware.
 *
 * `MODBUS_ERROR` stays the wire code for these failures (ADR-0067).
 */
function isSerialLibraryError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }

  // A transport fault the device recovers from is still a transport fault: without
  // this the backend would be told UNKNOWN_ERROR while the client reconnects
  // correctly, which reads as a bug in us rather than a cable someone pulled.
  if (hasRecoverableSerialCode(error)) {
    return true;
  }

  const message = error.message.toLowerCase();

  return (
    message.includes('port not open') ||
    message.includes('econnrefused') ||
    message.includes('timed out') ||
    message.includes('crc error')
  );
}

export function isReconnectableHardwareError(error: unknown): boolean {
  if (error instanceof HardwareTransportError) {
    return error.reconnectable;
  }

  if (serialErrorCode(error) === 'EACCES') {
    return false;
  }

  return hasRecoverableSerialCode(error);
}

/**
 * Errors we raise carry their own code. Only third-party transport failures are
 * inferred, and anything else is reported as unknown rather than guessed at
 * from wording — a reworded message used to change the code a device reported.
 */
export function mapErrorToMqttCode(error: unknown): MqttErrorCode {
  if (error instanceof LockerError) {
    return error.code;
  }

  if (isSerialLibraryError(error)) {
    return MqttErrorCode.MODBUS_ERROR;
  }

  return MqttErrorCode.UNKNOWN_ERROR;
}
