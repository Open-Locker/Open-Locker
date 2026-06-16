"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.readAsyncApiExample = readAsyncApiExample;
exports.assertMatchesSchema = assertMatchesSchema;
const strict_1 = __importDefault(require("node:assert/strict"));
const node_fs_1 = __importDefault(require("node:fs"));
const node_path_1 = __importDefault(require("node:path"));
const node_url_1 = require("node:url");
const _2020_1 = __importDefault(require("ajv/dist/2020"));
const ajv_formats_1 = __importDefault(require("ajv-formats"));
const repoRoot = node_path_1.default.resolve(process.cwd(), '..');
const asyncApiRoot = node_path_1.default.join(repoRoot, 'docs', 'asyncapi');
const schemaRoot = node_path_1.default.join(asyncApiRoot, 'schemas');
function readJson(filePath) {
    return JSON.parse(node_fs_1.default.readFileSync(filePath, 'utf8'));
}
function listJsonFiles(directory) {
    const entries = node_fs_1.default.readdirSync(directory, { withFileTypes: true });
    return entries.flatMap((entry) => {
        const entryPath = node_path_1.default.join(directory, entry.name);
        if (entry.isDirectory()) {
            return listJsonFiles(entryPath);
        }
        return entry.isFile() && entry.name.endsWith('.json') ? [entryPath] : [];
    });
}
function schemaWithFileId(filePath) {
    const schema = readJson(filePath);
    return {
        ...schema,
        $id: (0, node_url_1.pathToFileURL)(filePath).href,
    };
}
function createAjv() {
    const ajv = new _2020_1.default({ allErrors: true, strict: false });
    (0, ajv_formats_1.default)(ajv);
    for (const schemaPath of listJsonFiles(schemaRoot)) {
        ajv.addSchema(schemaWithFileId(schemaPath));
    }
    return ajv;
}
function readAsyncApiExample(exampleFileName) {
    return readJson(node_path_1.default.join(asyncApiRoot, 'examples', exampleFileName));
}
function assertMatchesSchema(schemaRelativePath, payload) {
    const schemaPath = node_path_1.default.join(schemaRoot, schemaRelativePath);
    const validate = createAjv().getSchema((0, node_url_1.pathToFileURL)(schemaPath).href);
    strict_1.default.ok(validate, `Schema not loaded: ${schemaRelativePath}`);
    strict_1.default.equal(validate(payload), true, JSON.stringify(validate.errors, null, 2));
}
