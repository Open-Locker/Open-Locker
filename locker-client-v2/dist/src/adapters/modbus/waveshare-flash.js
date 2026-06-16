"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ALL_RELAYS_ADDRESS = exports.FLASH_OFF_BASE_ADDRESS = exports.FLASH_ON_BASE_ADDRESS = void 0;
exports.flashRelayOn = flashRelayOn;
exports.turnAllRelaysOff = turnAllRelaysOff;
exports.resolveFlashDurationMs = resolveFlashDurationMs;
const compartment_1 = require("../../domain/compartment");
exports.FLASH_ON_BASE_ADDRESS = 0x0200;
exports.FLASH_OFF_BASE_ADDRESS = 0x0400;
exports.ALL_RELAYS_ADDRESS = 0x00ff;
async function flashRelayOn(client, slaveId, address, durationMs) {
    const flashAddress = exports.FLASH_ON_BASE_ADDRESS + address;
    const steps = (0, compartment_1.toFlashDurationSteps)(durationMs);
    await writeRawFc5(client, slaveId, flashAddress, steps);
}
async function turnAllRelaysOff(client, slaveId) {
    await writeRawFc5(client, slaveId, exports.ALL_RELAYS_ADDRESS, 0x0000);
}
async function writeRawFc5(client, slaveId, dataAddress, value) {
    client.setID(slaveId);
    const payload = Buffer.from([
        (dataAddress >> 8) & 0xff,
        dataAddress & 0xff,
        (value >> 8) & 0xff,
        value & 0xff,
    ]);
    await client.customFunction(0x05, payload);
}
function resolveFlashDurationMs(configured) {
    if (configured === undefined) {
        return compartment_1.DEFAULT_FLASH_DURATION_MS;
    }
    return configured;
}
