"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ApplyConfigUseCase = void 0;
const config_normalization_1 = require("../domain/config-normalization");
const errors_1 = require("../domain/errors");
class ApplyConfigUseCase {
    deps;
    constructor(deps) {
        this.deps = deps;
    }
    async execute(command) {
        const previous = this.deps.overlayStore.load();
        try {
            const overlay = this.buildOverlay(command);
            this.deps.overlayStore.save(overlay);
            this.deps.config.reload();
            this.deps.restartHeartbeat();
            await this.deps.bus.reloadRuntimeConfig();
            this.deps.restartPolling();
            return {
                appliedConfigHash: overlay.appliedConfigHash,
                message: 'Config applied.',
            };
        }
        catch (error) {
            await this.rollback(previous);
            throw error;
        }
    }
    buildOverlay(command) {
        const normalized = (0, config_normalization_1.normalizeCompartments)(command.data.compartments);
        this.validateCompartments(normalized);
        const hash = (0, config_normalization_1.computeAppliedConfigHash)(normalized);
        if (hash.toLowerCase() !== command.data.config_hash.toLowerCase()) {
            throw new errors_1.LockerError(errors_1.MqttErrorCode.INVALID_CONFIG, 'config_hash does not match the provided compartments mapping');
        }
        return {
            mqtt: { heartbeatInterval: command.data.heartbeat_interval_seconds },
            compartments: normalized,
            appliedConfigHash: hash,
            updatedAt: new Date().toISOString(),
        };
    }
    validateCompartments(compartments) {
        const seenNumbers = new Set();
        const seenTargets = new Set();
        for (const c of compartments) {
            if (c.address > 7) {
                throw new errors_1.LockerError(errors_1.MqttErrorCode.INVALID_CONFIG, 'compartment addresses must be between 0 and 7');
            }
            if (seenNumbers.has(c.compartment_number)) {
                throw new errors_1.LockerError(errors_1.MqttErrorCode.INVALID_CONFIG, `duplicate compartment_number ${c.compartment_number}`);
            }
            const target = `${c.slaveId}:${c.address}`;
            if (seenTargets.has(target)) {
                throw new errors_1.LockerError(errors_1.MqttErrorCode.INVALID_CONFIG, `duplicate relay target ${target}`);
            }
            seenNumbers.add(c.compartment_number);
            seenTargets.add(target);
        }
    }
    async rollback(previous) {
        if (previous) {
            this.deps.overlayStore.save(previous);
        }
        else {
            this.deps.overlayStore.clear();
        }
        this.deps.config.reload();
        this.deps.restartHeartbeat();
        await this.deps.bus.reloadRuntimeConfig();
        this.deps.restartPolling();
    }
}
exports.ApplyConfigUseCase = ApplyConfigUseCase;
