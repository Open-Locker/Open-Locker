import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { assertNotProduction } from '../../src/adapters/simulator/production-guard';

const original = { APP_ENV: process.env.APP_ENV, NODE_ENV: process.env.NODE_ENV };

function setEnv(appEnv?: string, nodeEnv?: string): void {
  if (appEnv === undefined) {
    delete process.env.APP_ENV;
  } else {
    process.env.APP_ENV = appEnv;
  }

  if (nodeEnv === undefined) {
    delete process.env.NODE_ENV;
  } else {
    process.env.NODE_ENV = nodeEnv;
  }
}

afterEach(() => setEnv(original.APP_ENV, original.NODE_ENV));

test('a production NODE_ENV is caught even when APP_ENV is set to something else', () => {
  // The arrangement this repo actually produces: a Laravel-shaped .env supplies
  // APP_ENV while the Node runtime supplies NODE_ENV. Coalescing on the first
  // defined value would let this straight through.
  setEnv('local', 'production');

  assert.throws(() => assertNotProduction(false), /production/);
});

test('an empty APP_ENV does not mask a production NODE_ENV', () => {
  setEnv('', 'production');

  assert.throws(() => assertNotProduction(false), /production/);
});

test('a production APP_ENV is caught when NODE_ENV is absent', () => {
  setEnv('production', undefined);

  assert.throws(() => assertNotProduction(false), /production/);
});

test('the short form is caught too', () => {
  setEnv('prod', undefined);

  assert.throws(() => assertNotProduction(false), /prod/);
});

test('a development pair starts without complaint', () => {
  setEnv('local', 'development');

  assert.doesNotThrow(() => assertNotProduction(false));
});

test('neither variable set is allowed', () => {
  setEnv(undefined, undefined);

  assert.doesNotThrow(() => assertNotProduction(false));
});

test('an explicit override permits production rather than throwing', () => {
  setEnv('local', 'production');

  assert.doesNotThrow(() => assertNotProduction(true));
});
