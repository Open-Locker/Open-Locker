"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const outbound_envelope_1 = require("../../src/adapters/mqtt/outbound-envelope");
(0, node_test_1.test)("createEnvelope always adds message_id and timestamp", () => {
    const body = { type: "heartbeat", status: "online" };
    const envelope = (0, outbound_envelope_1.createEnvelope)(body, () => "2026-06-16T12:00:00.000Z");
    strict_1.default.equal(typeof envelope.message_id, "string");
    strict_1.default.equal(envelope.timestamp, "2026-06-16T12:00:00.000Z");
});
(0, node_test_1.test)("createEnvelope preserves existing message_id and timestamp", () => {
    const envelope = (0, outbound_envelope_1.createEnvelope)({
        message_id: "fixed-id",
        timestamp: "2026-06-16T12:00:00.000Z",
        action: "open_compartment",
    }, () => "should-not-be-used");
    strict_1.default.equal(envelope.message_id, "fixed-id");
    strict_1.default.equal(envelope.timestamp, "2026-06-16T12:00:00.000Z");
});
(0, node_test_1.test)("serializeOutboundPayload returns valid JSON", () => {
    const json = (0, outbound_envelope_1.serializeOutboundPayload)({ result: "success" }, () => "2026-06-16T12:00:00.000Z");
    const parsed = JSON.parse(json);
    strict_1.default.equal(typeof parsed.message_id, "string");
    strict_1.default.equal(parsed.timestamp, "2026-06-16T12:00:00.000Z");
});
