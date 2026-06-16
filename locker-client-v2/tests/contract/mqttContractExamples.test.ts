import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import { assertMatchesSchema, readAsyncApiExample } from './jsonSchema';

const exampleSchemaMappings = [
  ['command-open-compartment.json', 'payloads/command-open-compartment.json'],
  ['command-apply-config.json', 'payloads/command-apply-config.json'],
  ['response-open-success.json', 'payloads/response-command-success.json'],
  ['response-open-error.json', 'payloads/response-command-error.json'],
  ['response-apply-config-success.json', 'payloads/response-apply-config-success.json'],
  ['state-heartbeat.json', 'payloads/state-heartbeat.json'],
  ['state-snapshot.json', 'payloads/state-snapshot.json'],
  ['state-connection-lost.json', 'payloads/state-connection-lost.json'],
  ['provisioning-request.json', 'messages/provisioning-request.json'],
  ['provisioning-success.json', 'payloads/provisioning-success.json'],
  ['provisioning-error.json', 'payloads/provisioning-error.json'],
] as const;

test('schema mapping covers every AsyncAPI example', () => {
  const examplesDirectory = path.resolve(process.cwd(), '..', 'docs', 'asyncapi', 'examples');
  const mappedExampleFileNames = new Set<string>(
    exampleSchemaMappings.map(([exampleFileName]) => exampleFileName),
  );
  const exampleFileNames = fs
    .readdirSync(examplesDirectory)
    .filter((fileName) => fileName.endsWith('.json'))
    .toSorted();
  const unmappedExampleFileNames = exampleFileNames.filter(
    (fileName) => !mappedExampleFileNames.has(fileName),
  );

  assert.equal(
    unmappedExampleFileNames.length,
    0,
    `Missing schema mapping for AsyncAPI example(s): ${unmappedExampleFileNames.join(', ')}`,
  );
});

for (const [exampleFileName, schemaRelativePath] of exampleSchemaMappings) {
  test(`${exampleFileName} matches ${schemaRelativePath}`, () => {
    assertMatchesSchema(schemaRelativePath, readAsyncApiExample(exampleFileName));
  });
}
