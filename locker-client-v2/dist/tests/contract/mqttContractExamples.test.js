"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_fs_1 = __importDefault(require("node:fs"));
const node_path_1 = __importDefault(require("node:path"));
const node_test_1 = require("node:test");
const jsonSchema_1 = require("./jsonSchema");
const exampleSchemaMappings = [
    ["command-open-compartment.json", "payloads/command-open-compartment.json"],
    ["command-apply-config.json", "payloads/command-apply-config.json"],
    ["response-open-success.json", "payloads/response-command-success.json"],
    ["response-open-error.json", "payloads/response-command-error.json"],
    [
        "response-apply-config-success.json",
        "payloads/response-apply-config-success.json",
    ],
    ["state-heartbeat.json", "payloads/state-heartbeat.json"],
    ["state-snapshot.json", "payloads/state-snapshot.json"],
    ["state-connection-lost.json", "payloads/state-connection-lost.json"],
    ["provisioning-request.json", "messages/provisioning-request.json"],
    ["provisioning-success.json", "payloads/provisioning-success.json"],
    ["provisioning-error.json", "payloads/provisioning-error.json"],
];
(0, node_test_1.test)("schema mapping covers every AsyncAPI example", () => {
    const examplesDirectory = node_path_1.default.resolve(process.cwd(), "..", "docs", "asyncapi", "examples");
    const mappedExampleFileNames = new Set(exampleSchemaMappings.map(([exampleFileName]) => exampleFileName));
    const exampleFileNames = node_fs_1.default
        .readdirSync(examplesDirectory)
        .filter((fileName) => fileName.endsWith(".json"))
        .sort();
    const unmappedExampleFileNames = exampleFileNames.filter((fileName) => !mappedExampleFileNames.has(fileName));
    strict_1.default.equal(unmappedExampleFileNames.length, 0, `Missing schema mapping for AsyncAPI example(s): ${unmappedExampleFileNames.join(", ")}`);
});
for (const [exampleFileName, schemaRelativePath] of exampleSchemaMappings) {
    (0, node_test_1.test)(`${exampleFileName} matches ${schemaRelativePath}`, () => {
        (0, jsonSchema_1.assertMatchesSchema)(schemaRelativePath, (0, jsonSchema_1.readAsyncApiExample)(exampleFileName));
    });
}
