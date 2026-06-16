"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createEnvelope = createEnvelope;
exports.serializeOutboundPayload = serializeOutboundPayload;
const crypto_1 = require("crypto");
function createEnvelope(body, nowIso = () => new Date().toISOString()) {
    const envelope = { ...body };
    if (!('message_id' in envelope) || typeof envelope.message_id !== 'string') {
        envelope.message_id = (0, crypto_1.randomUUID)();
    }
    if (!('timestamp' in envelope) || typeof envelope.timestamp !== 'string') {
        envelope.timestamp = nowIso();
    }
    return envelope;
}
function serializeOutboundPayload(body, nowIso) {
    return JSON.stringify(createEnvelope(body, nowIso));
}
