"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const node_test_1 = require("node:test");
const outbound_envelope_1 = require("../../src/adapters/mqtt/outbound-envelope");
const mqtt_will_1 = require("../../src/infrastructure/mqtt-will");
const jsonSchema_1 = require("./jsonSchema");
(0, node_test_1.test)('heartbeat payload matches AsyncAPI schema', () => {
    const payload = JSON.parse((0, outbound_envelope_1.serializeOutboundPayload)({ uptime_seconds: 60 }, () => '2026-04-14T19:36:00Z'));
    (0, jsonSchema_1.assertMatchesSchema)('payloads/state-heartbeat.json', payload);
});
(0, node_test_1.test)('compartment snapshot payload matches AsyncAPI schema', () => {
    const payload = JSON.parse((0, outbound_envelope_1.serializeOutboundPayload)({
        compartments: [
            { compartment_number: 1, door_state: 'closed' },
            { compartment_number: 2, door_state: 'open' },
        ],
    }, () => '2026-04-14T19:36:05Z'));
    (0, jsonSchema_1.assertMatchesSchema)('payloads/state-snapshot.json', payload);
});
(0, node_test_1.test)('command success response matches AsyncAPI schema', () => {
    const payload = JSON.parse((0, outbound_envelope_1.serializeOutboundPayload)({
        type: 'command_response',
        action: 'open_compartment',
        result: 'success',
        transaction_id: 'tx-1',
        message: 'Compartment opened.',
    }, () => '2026-04-14T19:36:05Z'));
    (0, jsonSchema_1.assertMatchesSchema)('payloads/response-command-success.json', payload);
});
(0, node_test_1.test)('connection lost will payload matches AsyncAPI schema', () => {
    const will = (0, mqtt_will_1.connectionLostWillOptions)('locker-test', () => '2026-04-14T19:36:00Z').will;
    const payload = JSON.parse(String(will?.payload));
    (0, jsonSchema_1.assertMatchesSchema)('payloads/state-connection-lost.json', payload);
});
