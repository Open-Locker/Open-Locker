"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_fs_1 = __importDefault(require("node:fs"));
const node_path_1 = __importDefault(require("node:path"));
const node_test_1 = require("node:test");
const mqtt_schemas_1 = require("../../src/domain/mqtt-schemas");
const jsonSchema_1 = require("../contract/jsonSchema");
const commandExampleFiles = ['command-open-compartment.json', 'command-apply-config.json'];
for (const exampleFile of commandExampleFiles) {
    (0, node_test_1.test)(`${exampleFile} includes a non-empty transaction_id`, () => {
        const example = (0, jsonSchema_1.readAsyncApiExample)(exampleFile);
        const transactionId = example.transaction_id;
        if (typeof transactionId !== 'string') {
            strict_1.default.fail('transaction_id must be a string');
        }
        strict_1.default.ok(transactionId.trim().length > 0);
        strict_1.default.equal(mqtt_schemas_1.mqttCommandEnvelopeSchema.safeParse(example).success, true);
    });
}
(0, node_test_1.test)('known command schemas reject missing transaction_id', () => {
    const base = {
        message_id: 'msg-1',
        timestamp: '2026-04-11T10:00:00Z',
    };
    strict_1.default.equal(mqtt_schemas_1.openCompartmentCommandSchema.safeParse({
        ...base,
        action: 'open_compartment',
        data: { compartment_number: 1 },
    }).success, false);
    strict_1.default.equal(mqtt_schemas_1.applyConfigCommandSchema.safeParse({
        ...base,
        action: 'apply_config',
        data: {
            config_hash: 'a'.repeat(64),
            heartbeat_interval_seconds: 30,
            compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
        },
    }).success, false);
});
(0, node_test_1.test)('parseKnownMQTTCommand returns null when transaction_id is missing', () => {
    strict_1.default.equal((0, mqtt_schemas_1.parseKnownMQTTCommand)({
        action: 'open_compartment',
        message_id: 'msg-1',
        timestamp: '2026-04-11T10:00:00Z',
        data: { compartment_number: 1 },
    }), null);
});
(0, node_test_1.test)('every AsyncAPI command example validates against knownMQTTCommandSchema', () => {
    const examplesDirectory = node_path_1.default.resolve(process.cwd(), '..', 'docs', 'asyncapi', 'examples');
    const commandExamples = node_fs_1.default
        .readdirSync(examplesDirectory)
        .filter((fileName) => fileName.startsWith('command-') && fileName.endsWith('.json'));
    for (const fileName of commandExamples) {
        const example = (0, jsonSchema_1.readAsyncApiExample)(fileName);
        strict_1.default.equal(mqtt_schemas_1.knownMQTTCommandSchema.safeParse(example).success, true, `${fileName} must validate as a known inbound command with transaction_id`);
    }
});
