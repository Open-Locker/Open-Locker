"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.connectionLostWillOptions = connectionLostWillOptions;
const crypto_1 = require("crypto");
/**
 * Last Will for unexpected disconnect (AsyncAPI: locker/{uuid}/state/connection).
 */
function connectionLostWillOptions(lockerUuid, nowIso = () => new Date().toISOString()) {
    const topic = `locker/${lockerUuid}/state/connection`;
    const payload = JSON.stringify({
        message_id: (0, crypto_1.randomUUID)(),
        timestamp: nowIso(),
        status: 'offline',
        reason: 'mqtt_last_will',
    });
    return {
        will: {
            topic,
            payload,
            qos: 1,
            retain: false,
        },
    };
}
