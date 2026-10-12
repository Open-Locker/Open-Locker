/*
 * A small JSON Schema validator for the AsyncAPI schemas in docs/asyncapi.
 * Node built-ins only. It knows exactly the keywords those schemas use and
 * fails on any other, so a schema that starts using something new cannot pass
 * unchecked.
 */

import fs from 'node:fs';
import path from 'node:path';

const IGNORED = new Set(['$schema', '$id', 'title', 'description']);
const DATE_TIME = /^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/;
const UUID = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;

const typeOf = (value) => {
  if (value === null) return 'null';
  if (Array.isArray(value)) return 'array';
  if (Number.isInteger(value)) return 'integer';
  return typeof value;
};

const isType = (value, type) => {
  const actual = typeOf(value);
  return actual === type || (type === 'number' && actual === 'integer');
};

const equal = (a, b) => JSON.stringify(a) === JSON.stringify(b);

function validTimestamp(text) {
  if (!DATE_TIME.test(text)) return false;
  const [year, month, day, hour, minute, second] = text.match(/\d+/g).slice(0, 6).map(Number);
  const days = [31, year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  return month >= 1 && month <= 12 && day >= 1 && day <= days[month - 1] && hour <= 23 && minute <= 59 && second <= 60;
}

export class SchemaSet {
  #cache = new Map();

  load(file) {
    const resolved = path.resolve(file);
    if (!this.#cache.has(resolved)) {
      this.#cache.set(resolved, JSON.parse(fs.readFileSync(resolved, 'utf8')));
    }
    return this.#cache.get(resolved);
  }

  /** Errors of `value` against the schema in `file`; empty when it is valid. */
  validateFile(file, value) {
    const errors = [];
    this.#check(this.load(file), path.resolve(file), value, '$', errors);
    return errors;
  }

  #check(schema, file, value, where, errors) {
    if (typeof schema === 'boolean') {
      if (!schema) errors.push(`${where}: not allowed`);
      return;
    }
    for (const [keyword, rule] of Object.entries(schema)) {
      if (IGNORED.has(keyword)) continue;
      switch (keyword) {
        case '$ref': {
          if (rule.includes('#')) throw new Error(`${file}: $ref with a fragment is not supported`);
          const target = path.resolve(path.dirname(file), rule);
          this.#check(this.load(target), target, value, where, errors);
          break;
        }
        case 'allOf':
          for (const part of rule) this.#check(part, file, value, where, errors);
          break;
        case 'type': {
          const types = Array.isArray(rule) ? rule : [rule];
          if (!types.some((type) => isType(value, type))) {
            errors.push(`${where}: expected ${types.join(' or ')}, got ${typeOf(value)}`);
          }
          break;
        }
        case 'const':
          if (!equal(value, rule)) errors.push(`${where}: expected ${JSON.stringify(rule)}`);
          break;
        case 'enum':
          if (!rule.some((option) => equal(value, option))) {
            errors.push(`${where}: ${JSON.stringify(value)} is not one of ${JSON.stringify(rule)}`);
          }
          break;
        case 'required':
          if (typeOf(value) === 'object') {
            for (const key of rule) {
              if (!(key in value)) errors.push(`${where}: missing "${key}"`);
            }
          }
          break;
        case 'properties':
          if (typeOf(value) === 'object') {
            for (const [key, sub] of Object.entries(rule)) {
              if (key in value) this.#check(sub, file, value[key], `${where}.${key}`, errors);
            }
          }
          break;
        case 'additionalProperties':
          if (typeOf(value) === 'object') {
            const known = new Set(Object.keys(schema.properties ?? {}));
            for (const key of Object.keys(value).filter((key) => !known.has(key))) {
              if (rule === false) errors.push(`${where}: "${key}" is not allowed`);
              else if (rule !== true) this.#check(rule, file, value[key], `${where}.${key}`, errors);
            }
          }
          break;
        case 'items':
          if (Array.isArray(value)) {
            value.forEach((item, index) => this.#check(rule, file, item, `${where}[${index}]`, errors));
          }
          break;
        case 'minItems':
          if (Array.isArray(value) && value.length < rule) errors.push(`${where}: fewer than ${rule} items`);
          break;
        case 'minLength':
          if (typeof value === 'string' && [...value].length < rule) errors.push(`${where}: shorter than ${rule}`);
          break;
        case 'minimum':
          if (typeof value === 'number' && value < rule) errors.push(`${where}: below ${rule}`);
          break;
        case 'pattern':
          if (typeof value === 'string' && !new RegExp(rule).test(value)) {
            errors.push(`${where}: does not match ${rule}`);
          }
          break;
        case 'format':
          if (typeof value === 'string') {
            if (rule === 'date-time' && !validTimestamp(value)) errors.push(`${where}: not a date-time`);
            else if (rule === 'uuid' && !UUID.test(value)) errors.push(`${where}: not a uuid`);
            else if (rule !== 'date-time' && rule !== 'uuid') throw new Error(`${file}: format "${rule}" is not supported`);
          }
          break;
        default:
          throw new Error(`${file}: keyword "${keyword}" is not supported by the validator`);
      }
    }
  }
}
