"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.HeartbeatUseCase = exports.PollCompartmentStateUseCase = void 0;
const logging_1 = require("../infrastructure/logging");
class PollCompartmentStateUseCase {
    bus;
    config;
    outbound;
    snapshotTopic;
    polling = false;
    constructor(bus, config, outbound, snapshotTopic) {
        this.bus = bus;
        this.config = config;
        this.outbound = outbound;
        this.snapshotTopic = snapshotTopic;
    }
    async pollAndPublish(force = false) {
        if (this.polling && !force) {
            return;
        }
        this.polling = true;
        try {
            const entries = await this.collectSnapshots();
            await this.outbound.publishJson(this.snapshotTopic, { compartments: entries }, { qos: 1, retain: true });
        }
        catch (error) {
            logging_1.logger.warn('Compartment snapshot publish failed', {
                error: error instanceof Error ? error.message : String(error),
            });
        }
        finally {
            this.polling = false;
        }
    }
    async collectSnapshots() {
        const compartments = this.config.load().compartments ?? [];
        const entries = [];
        if (compartments.length === 0) {
            return entries;
        }
        for (const compartment of compartments) {
            const target = {
                compartmentNumber: compartment.compartment_number,
                relayAddress: compartment.address,
                slaveId: compartment.slaveId,
            };
            try {
                const doorState = await this.bus.readDoorSensor(target);
                entries.push({
                    compartment_number: compartment.compartment_number,
                    door_state: doorState,
                });
            }
            catch {
                entries.push({
                    compartment_number: compartment.compartment_number,
                    door_state: 'unknown',
                });
            }
        }
        return entries;
    }
}
exports.PollCompartmentStateUseCase = PollCompartmentStateUseCase;
class HeartbeatUseCase {
    outbound;
    topic;
    intervalMs;
    timer = null;
    startTime = Date.now();
    constructor(outbound, topic, intervalMs) {
        this.outbound = outbound;
        this.topic = topic;
        this.intervalMs = intervalMs;
    }
    start() {
        this.stop();
        void this.publish();
        this.timer = setInterval(() => {
            void this.publish();
        }, this.intervalMs);
    }
    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }
    restart(intervalMs) {
        if (intervalMs !== undefined) {
            this.intervalMs = intervalMs;
        }
        this.start();
    }
    async publish() {
        const uptimeSeconds = Math.floor((Date.now() - this.startTime) / 1000);
        try {
            await this.outbound.publishJson(this.topic, { uptime_seconds: uptimeSeconds }, { qos: 1 });
        }
        catch (error) {
            logging_1.logger.warn('Heartbeat publish failed', {
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }
}
exports.HeartbeatUseCase = HeartbeatUseCase;
