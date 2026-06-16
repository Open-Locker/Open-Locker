"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_fs_1 = __importDefault(require("node:fs"));
const node_path_1 = __importDefault(require("node:path"));
const node_test_1 = require("node:test");
const js_yaml_1 = __importDefault(require("js-yaml"));
const asyncApiRoot = node_path_1.default.resolve(process.cwd(), '..', 'docs', 'asyncapi');
function listFiles(directory) {
    const entries = node_fs_1.default.readdirSync(directory, { withFileTypes: true });
    return entries.flatMap((entry) => {
        const entryPath = node_path_1.default.join(directory, entry.name);
        if (entry.isDirectory()) {
            return listFiles(entryPath);
        }
        return entry.isFile() ? [entryPath] : [];
    });
}
(0, node_test_1.test)('AsyncAPI document is valid YAML', () => {
    const document = js_yaml_1.default.load(node_fs_1.default.readFileSync(node_path_1.default.join(asyncApiRoot, 'mqtt.yaml'), 'utf8'));
    strict_1.default.ok(document);
});
(0, node_test_1.test)('AsyncAPI schemas and examples are valid JSON', () => {
    const jsonFiles = listFiles(asyncApiRoot).filter((filePath) => filePath.endsWith('.json'));
    strict_1.default.ok(jsonFiles.length > 0);
    for (const jsonFile of jsonFiles) {
        JSON.parse(node_fs_1.default.readFileSync(jsonFile, 'utf8'));
    }
});
