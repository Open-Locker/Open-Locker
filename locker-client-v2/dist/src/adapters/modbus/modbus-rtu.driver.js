"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ModbusRtuDriver = void 0;
const modbus_serial_1 = __importDefault(require("modbus-serial"));
const waveshare_flash_1 = require("./waveshare-flash");
class ModbusRtuDriver {
    connection;
    client = null;
    constructor(connection) {
        this.connection = connection;
    }
    async connect() {
        if (this.client?.isOpen) {
            return;
        }
        if (this.client) {
            try {
                await this.disconnect();
            }
            catch {
                this.client = null;
            }
        }
        this.client = new modbus_serial_1.default();
        try {
            await this.client.connectRTUBuffered(this.connection.port, {
                baudRate: this.connection.baudRate,
                dataBits: this.connection.dataBits,
                stopBits: this.connection.stopBits,
                parity: this.connection.parity,
            });
            this.client.setTimeout(this.connection.timeout);
        }
        catch (error) {
            this.client = null;
            throw error;
        }
    }
    async disconnect() {
        if (!this.client) {
            return;
        }
        await new Promise((resolve) => {
            this.client.close(() => resolve());
        });
        this.client = null;
    }
    isOpen() {
        return Boolean(this.client?.isOpen);
    }
    async flashRelayOn(slaveId, address, durationMs) {
        await (0, waveshare_flash_1.flashRelayOn)(this.getWaveshareClient(), slaveId, address, durationMs);
    }
    async readCoils(slaveId, address, length) {
        this.getClient().setID(slaveId);
        const result = await this.getClient().readCoils(address, length);
        return result.data;
    }
    async readDiscreteInputs(slaveId, address, length) {
        this.getClient().setID(slaveId);
        const result = await this.getClient().readDiscreteInputs(address, length);
        return result.data;
    }
    async turnAllRelaysOff(slaveId) {
        await (0, waveshare_flash_1.turnAllRelaysOff)(this.getWaveshareClient(), slaveId);
    }
    getClient() {
        if (!this.client?.isOpen) {
            throw new Error('Port Not Open');
        }
        return this.client;
    }
    getWaveshareClient() {
        const client = this.getClient();
        if (typeof client.customFunction !== 'function') {
            throw new Error('modbus-serial customFunction API is unavailable');
        }
        return client;
    }
}
exports.ModbusRtuDriver = ModbusRtuDriver;
