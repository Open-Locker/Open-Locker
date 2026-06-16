"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OutboundMqttAdapter = void 0;
const outbound_envelope_1 = require("./outbound-envelope");
class OutboundMqttAdapter {
    publishRaw;
    responseTopic;
    nowIso;
    constructor(publishRaw, responseTopic, nowIso = () => new Date().toISOString()) {
        this.publishRaw = publishRaw;
        this.responseTopic = responseTopic;
        this.nowIso = nowIso;
    }
    async publishJson(topic, body, options) {
        const payload = (0, outbound_envelope_1.serializeOutboundPayload)(body, this.nowIso);
        await this.publishRaw(topic, payload, options);
    }
    async publishCommandResponse(body) {
        await this.publishJson(this.responseTopic, { ...body }, { qos: 1 });
    }
}
exports.OutboundMqttAdapter = OutboundMqttAdapter;
