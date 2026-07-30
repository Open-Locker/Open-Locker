export enum MqttErrorCode {
  DOOR_JAMMED = 'DOOR_JAMMED',
  COMPARTMENT_NOT_FOUND = 'COMPARTMENT_NOT_FOUND',
  RUNTIME_CONFIG_NOT_APPLIED = 'RUNTIME_CONFIG_NOT_APPLIED',
  HARDWARE_ERROR = 'HARDWARE_ERROR',
  MODBUS_ERROR = 'MODBUS_ERROR',
  INVALID_COMMAND = 'INVALID_COMMAND',
  UNKNOWN_ACTION = 'UNKNOWN_ACTION',
  MISSING_MESSAGE_ID = 'MISSING_MESSAGE_ID',
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

export class ModbusTransportError extends LockerError {
  /**
   * @param reconnectable Whether dropping the port and dialling again could
   *   clear this. A closed port can be reopened; a missing library API cannot.
   */
  constructor(
    message: string,
    public readonly reconnectable = false,
  ) {
    super(MqttErrorCode.MODBUS_ERROR, message);
    this.name = 'ModbusTransportError';
  }
}

/**
 * Transport failures raised by `modbus-serial` itself.
 *
 * The library throws plain `Error`s, so its message text is the only signal
 * available. Matching it is fragile, which is why it is confined to this one
 * function: everything we raise ourselves carries an explicit code instead.
 */
function isModbusLibraryError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }

  const message = error.message.toLowerCase();

  return (
    message.includes('port not open') ||
    message.includes('econnrefused') ||
    message.includes('timed out') ||
    message.includes('crc error') ||
    message.includes('modbus')
  );
}

export function isReconnectableModbusError(error: unknown): boolean {
  if (error instanceof ModbusTransportError) {
    return error.reconnectable;
  }

  return (
    error instanceof Error &&
    (error.message.includes('Port Not Open') || error.message.includes('ECONNREFUSED'))
  );
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

  if (isModbusLibraryError(error)) {
    return MqttErrorCode.MODBUS_ERROR;
  }

  return MqttErrorCode.UNKNOWN_ERROR;
}
