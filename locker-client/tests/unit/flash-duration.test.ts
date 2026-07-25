import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  MAX_FLASH_DURATION_MS,
  MIN_FLASH_DURATION_MS,
  normalizeFlashDurationMs,
  toFlashDurationSteps,
} from '../../src/domain/compartment';

test('normalizeFlashDurationMs accepts default range', () => {
  assert.equal(normalizeFlashDurationMs(200), 200);
  assert.equal(normalizeFlashDurationMs(undefined), 200);
});

test('normalizeFlashDurationMs rejects below minimum', () => {
  assert.throws(() => normalizeFlashDurationMs(50), /at least 100ms/);
});

test('normalizeFlashDurationMs rejects above maximum', () => {
  assert.throws(() => normalizeFlashDurationMs(9999), /must not exceed 500ms/);
});

test('toFlashDurationSteps rounds up to 100ms units', () => {
  assert.equal(toFlashDurationSteps(200), 2);
  assert.equal(toFlashDurationSteps(150), 2);
  assert.equal(MIN_FLASH_DURATION_MS, 100);
  assert.equal(MAX_FLASH_DURATION_MS, 500);
});
