import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { pathToFileURL } from "node:url";
import Ajv2020 from "ajv/dist/2020";
import addFormats from "ajv-formats";

type JsonSchema = Record<string, unknown>;

const repoRoot = path.resolve(process.cwd(), "..");
const asyncApiRoot = path.join(repoRoot, "docs", "asyncapi");
const schemaRoot = path.join(asyncApiRoot, "schemas");

function readJson(filePath: string): unknown {
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function listJsonFiles(directory: string): string[] {
  const entries = fs.readdirSync(directory, { withFileTypes: true });

  return entries.flatMap((entry) => {
    const entryPath = path.join(directory, entry.name);

    if (entry.isDirectory()) {
      return listJsonFiles(entryPath);
    }

    return entry.isFile() && entry.name.endsWith(".json") ? [entryPath] : [];
  });
}

function schemaWithFileId(filePath: string): JsonSchema {
  const schema = readJson(filePath) as JsonSchema;

  return {
    ...schema,
    $id: pathToFileURL(filePath).href,
  };
}

function createAjv() {
  const ajv = new Ajv2020({ allErrors: true, strict: false });
  addFormats(ajv);

  for (const schemaPath of listJsonFiles(schemaRoot)) {
    ajv.addSchema(schemaWithFileId(schemaPath));
  }

  return ajv;
}

export function readAsyncApiExample(exampleFileName: string): unknown {
  return readJson(path.join(asyncApiRoot, "examples", exampleFileName));
}

export function assertMatchesSchema(
  schemaRelativePath: string,
  payload: unknown,
): void {
  const schemaPath = path.join(schemaRoot, schemaRelativePath);
  const validate = createAjv().getSchema(pathToFileURL(schemaPath).href);

  assert.ok(validate, `Schema not loaded: ${schemaRelativePath}`);
  assert.equal(
    validate(payload),
    true,
    JSON.stringify(validate.errors, null, 2),
  );
}
