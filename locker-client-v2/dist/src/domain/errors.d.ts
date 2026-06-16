export declare enum MqttErrorCode {
    DOOR_JAMMED = "DOOR_JAMMED",
    COMPARTMENT_NOT_FOUND = "COMPARTMENT_NOT_FOUND",
    HARDWARE_ERROR = "HARDWARE_ERROR",
    MODBUS_ERROR = "MODBUS_ERROR",
    INVALID_COMMAND = "INVALID_COMMAND",
    INVALID_CONFIG = "INVALID_CONFIG",
    TIMEOUT = "TIMEOUT",
    UNKNOWN_ERROR = "UNKNOWN_ERROR"
}
export declare class LockerError extends Error {
    readonly code: MqttErrorCode;
    constructor(code: MqttErrorCode, message: string);
}
export declare class ModbusTransportError extends LockerError {
    constructor(message: string);
}
export declare function isReconnectableModbusError(error: unknown): boolean;
export declare function mapErrorToMqttCode(error: unknown): MqttErrorCode;
