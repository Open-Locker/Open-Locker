"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OpenCompartmentUseCase = void 0;
exports.runStartupFailsafe = runStartupFailsafe;
const errors_1 = require("../domain/errors");
class OpenCompartmentUseCase {
    bus;
    config;
    scheduler;
    monitoringIntervalMs;
    monitoringKeys = new Set();
    constructor(bus, config, scheduler, monitoringIntervalMs = 500) {
        this.bus = bus;
        this.config = config;
        this.scheduler = scheduler;
        this.monitoringIntervalMs = monitoringIntervalMs;
    }
    async execute(compartmentNumber) {
        const connected = await this.bus.ensureConnected();
        if (!connected) {
            throw new errors_1.LockerError(errors_1.MqttErrorCode.MODBUS_ERROR, 'Cannot open compartment: Modbus connection unavailable');
        }
        const target = this.resolveTarget(compartmentNumber);
        const durationMs = this.config.getFlashDurationMs();
        await this.bus.flashRelay(target, durationMs);
        this.startRelayMonitoring(target);
    }
    stopAllMonitoring() {
        this.scheduler.cancelAll();
        this.monitoringKeys.clear();
    }
    resolveTarget(compartmentNumber) {
        const compartment = this.config.getCompartmentConfig(compartmentNumber);
        if (!compartment) {
            if (this.config.hasExplicitRuntimeCompartments()) {
                throw new errors_1.LockerError(errors_1.MqttErrorCode.COMPARTMENT_NOT_FOUND, `Compartment ${compartmentNumber} is not configured on this client`);
            }
            const relayAddress = compartmentNumber - 1;
            if (relayAddress < 0 || relayAddress > 7) {
                throw new errors_1.LockerError(errors_1.MqttErrorCode.COMPARTMENT_NOT_FOUND, `Invalid compartment number: ${compartmentNumber}`);
            }
            const slaveIds = this.bus.getConfiguredSlaveIds();
            return {
                compartmentNumber,
                relayAddress,
                slaveId: slaveIds[0] ?? 1,
            };
        }
        return {
            compartmentNumber,
            relayAddress: compartment.address,
            slaveId: compartment.slaveId,
        };
    }
    startRelayMonitoring(target) {
        if (this.monitoringKeys.has(target.compartmentNumber)) {
            return;
        }
        this.monitoringKeys.add(target.compartmentNumber);
        const tick = async () => {
            try {
                const relayOn = await this.bus.readRelayState(target);
                if (!relayOn) {
                    this.monitoringKeys.delete(target.compartmentNumber);
                    return;
                }
            }
            catch {
                this.monitoringKeys.delete(target.compartmentNumber);
                return;
            }
            this.scheduler.scheduleAfter(this.monitoringIntervalMs, tick);
        };
        void tick();
    }
}
exports.OpenCompartmentUseCase = OpenCompartmentUseCase;
async function runStartupFailsafe(bus) {
    const slaveIds = bus.getConfiguredSlaveIds();
    let successCount = 0;
    for (const slaveId of slaveIds) {
        try {
            await bus.turnAllRelaysOff(slaveId);
            successCount++;
        }
        catch {
            // continue per ADR-0006
        }
    }
    if (successCount === 0 && slaveIds.length > 0) {
        throw new Error('Startup failsafe: all Modbus boards unreachable');
    }
}
