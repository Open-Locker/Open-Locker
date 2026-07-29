export enum MqttErrorCode {
  DOOR_JAMMED = 'DOOR_JAMMED',
  COMPARTMENT_NOT_FOUND = 'COMPARTMENT_NOT_FOUND',
  RUNTIME_CONFIG_NOT_APPLIED = 'RUNTIME_CONFIG_NOT_APPLIED',
  HARDWARE_ERROR = 'HARDWARE_ERROR',
  MODBUS_ERROR = 'MODBUS_ERROR',
  INVALID_COMMAND = 'INVALID_COMMAND',
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
  constructor(message: string) {
    super(MqttErrorCode.MODBUS_ERROR, message);
    this.name = 'ModbusTransportError';
  }
}

export function isReconnectableModbusError(error: unknown): boolean {
  return (
    error instanceof Error &&
    (error.message.includes('Port Not Open') || error.message.includes('ECONNREFUSED'))
  );
}

export function mapErrorToMqttCode(error: unknown): MqttErrorCode {
  if (error instanceof LockerError) {
    return error.code;
  }

  if (error instanceof Error) {
    const msg = error.message.toLowerCase();
    if (msg.includes('jammed')) {
      return MqttErrorCode.DOOR_JAMMED;
    }
    if (msg.includes('not found') || msg.includes('not configured')) {
      return MqttErrorCode.COMPARTMENT_NOT_FOUND;
    }
    if (msg.includes('modbus') || msg.includes('port not open')) {
      return MqttErrorCode.MODBUS_ERROR;
    }
    if (msg.includes('invalid config') || msg.includes('config_hash')) {
      return MqttErrorCode.INVALID_CONFIG;
    }
  }

  return MqttErrorCode.UNKNOWN_ERROR;
}
