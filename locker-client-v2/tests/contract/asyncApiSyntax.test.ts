import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { test } from "node:test";
import yaml from "js-yaml";

const asyncApiRoot = path.resolve(process.cwd(), "..", "docs", "asyncapi");

function listFiles(directory: string): string[] {
  const entries = fs.readdirSync(directory, { withFileTypes: true });

  return entries.flatMap((entry) => {
    const entryPath = path.join(directory, entry.name);

    if (entry.isDirectory()) {
      return listFiles(entryPath);
    }

    return entry.isFile() ? [entryPath] : [];
  });
}

test("AsyncAPI document is valid YAML", () => {
  const document = yaml.load(
    fs.readFileSync(path.join(asyncApiRoot, "mqtt.yaml"), "utf8"),
  );

  assert.ok(document);
});

test("AsyncAPI schemas and examples are valid JSON", () => {
  const jsonFiles = listFiles(asyncApiRoot).filter((filePath) =>
    filePath.endsWith(".json"),
  );

  assert.ok(jsonFiles.length > 0);

  for (const jsonFile of jsonFiles) {
    JSON.parse(fs.readFileSync(jsonFile, "utf8"));
  }
});
