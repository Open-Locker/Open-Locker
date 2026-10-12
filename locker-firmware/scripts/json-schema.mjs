/*
 * Validates messages against the AsyncAPI schemas in docs/asyncapi/schemas,
 * with the same ajv setup as locker-client/tests/contract/jsonSchema.ts.
 */

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

function listJsonFiles(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const entryPath = path.join(directory, entry.name);
    if (entry.isDirectory()) return listJsonFiles(entryPath);
    return entry.isFile() && entry.name.endsWith('.json') ? [entryPath] : [];
  });
}

export class SchemaSet {
  #ajv = new Ajv2020({ allErrors: true, strict: false });

  constructor(schemaRoot) {
    addFormats(this.#ajv);
    // A file URL as $id lets the relative $refs between schema files resolve.
    for (const file of listJsonFiles(schemaRoot)) {
      const schema = JSON.parse(fs.readFileSync(file, 'utf8'));
      this.#ajv.addSchema({ ...schema, $id: pathToFileURL(file).href });
    }
  }

  /** The problems of `value` against the schema in `file`; empty when it is valid. */
  validateFile(file, value) {
    const validate = this.#ajv.getSchema(pathToFileURL(path.resolve(file)).href);
    if (!validate) throw new Error(`schema not loaded: ${file}`);
    if (validate(value)) return [];
    return validate.errors.map((error) => `${error.instancePath || '$'} ${error.message}`);
  }
}
