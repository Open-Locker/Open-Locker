"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ModbusTransportError = exports.LockerError = exports.MqttErrorCode = void 0;
exports.isReconnectableModbusError = isReconnectableModbusError;
exports.mapErrorToMqttCode = mapErrorToMqttCode;
var MqttErrorCode;
(function (MqttErrorCode) {
    MqttErrorCode["DOOR_JAMMED"] = "DOOR_JAMMED";
    MqttErrorCode["COMPARTMENT_NOT_FOUND"] = "COMPARTMENT_NOT_FOUND";
    MqttErrorCode["HARDWARE_ERROR"] = "HARDWARE_ERROR";
    MqttErrorCode["MODBUS_ERROR"] = "MODBUS_ERROR";
    MqttErrorCode["INVALID_COMMAND"] = "INVALID_COMMAND";
    MqttErrorCode["INVALID_CONFIG"] = "INVALID_CONFIG";
    MqttErrorCode["TIMEOUT"] = "TIMEOUT";
    MqttErrorCode["UNKNOWN_ERROR"] = "UNKNOWN_ERROR";
})(MqttErrorCode || (exports.MqttErrorCode = MqttErrorCode = {}));
class LockerError extends Error {
    code;
    constructor(code, message) {
        super(message);
        this.code = code;
        this.name = "LockerError";
    }
}
exports.LockerError = LockerError;
class ModbusTransportError extends LockerError {
    constructor(message) {
        super(MqttErrorCode.MODBUS_ERROR, message);
        this.name = "ModbusTransportError";
    }
}
exports.ModbusTransportError = ModbusTransportError;
function isReconnectableModbusError(error) {
    return (error instanceof Error &&
        (error.message.includes("Port Not Open") ||
            error.message.includes("ECONNREFUSED")));
}
function mapErrorToMqttCode(error) {
    if (error instanceof LockerError) {
        return error.code;
    }
    if (error instanceof Error) {
        const msg = error.message.toLowerCase();
        if (msg.includes("jammed")) {
            return MqttErrorCode.DOOR_JAMMED;
        }
        if (msg.includes("not found") || msg.includes("not configured")) {
            return MqttErrorCode.COMPARTMENT_NOT_FOUND;
        }
        if (msg.includes("modbus") || msg.includes("port not open")) {
            return MqttErrorCode.MODBUS_ERROR;
        }
        if (msg.includes("invalid config") || msg.includes("config_hash")) {
            return MqttErrorCode.INVALID_CONFIG;
        }
    }
    return MqttErrorCode.UNKNOWN_ERROR;
}
