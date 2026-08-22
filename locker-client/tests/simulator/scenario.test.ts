import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseScenario, ScenarioValidationError } from '../../src/adapters/simulator/scenario';

const minimalBank = {
  name: 'main',
  provisioning_token: 'token-a',
  compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
};

test('applies documented defaults', () => {
  const scenario = parseScenario({ banks: [minimalBank] });
  const bank = scenario.banks[0]!;

  assert.equal(bank.heartbeat_interval_seconds, 15);
  assert.equal(bank.flash_duration_ms, 200);
  assert.equal(bank.latency_ms, 0);
  assert.equal(bank.compartments[0]!.door_state, 'closed');
});

test('keeps explicit door states', () => {
  const scenario = parseScenario({
    banks: [
      {
        ...minimalBank,
        compartments: [{ compartment_number: 1, slaveId: 1, address: 0, door_state: 'open' }],
      },
    ],
  });

  assert.equal(scenario.banks[0]!.compartments[0]!.door_state, 'open');
});

test('rejects a scenario with no banks', () => {
  assert.throws(() => parseScenario({ banks: [] }), ScenarioValidationError);
});

test('rejects duplicate provisioning tokens across banks', () => {
  assert.throws(
    () =>
      parseScenario({
        banks: [minimalBank, { ...minimalBank, name: 'annex' }],
      }),
    (error: unknown) =>
      error instanceof ScenarioValidationError &&
      /Duplicate provisioning_token/.test(error.message),
  );
});

test('rejects duplicate compartment numbers within a bank', () => {
  assert.throws(
    () =>
      parseScenario({
        banks: [
          {
            ...minimalBank,
            compartments: [
              { compartment_number: 1, slaveId: 1, address: 0 },
              { compartment_number: 1, slaveId: 1, address: 1 },
            ],
          },
        ],
      }),
    (error: unknown) =>
      error instanceof ScenarioValidationError &&
      /duplicate compartment_number/.test(error.message),
  );
});

test('rejects two compartments sharing one relay target', () => {
  assert.throws(
    () =>
      parseScenario({
        banks: [
          {
            ...minimalBank,
            compartments: [
              { compartment_number: 1, slaveId: 1, address: 0 },
              { compartment_number: 2, slaveId: 1, address: 0 },
            ],
          },
        ],
      }),
    (error: unknown) =>
      error instanceof ScenarioValidationError && /duplicate relay target/.test(error.message),
  );
});

test('rejects relay addresses outside the supported range', () => {
  assert.throws(
    () =>
      parseScenario({
        banks: [
          { ...minimalBank, compartments: [{ compartment_number: 1, slaveId: 1, address: 8 }] },
        ],
      }),
    ScenarioValidationError,
  );
});

test('rejects flash durations the hardware contract forbids', () => {
  assert.throws(
    () => parseScenario({ banks: [{ ...minimalBank, flash_duration_ms: 600 }] }),
    ScenarioValidationError,
  );
});

test('reports every validation issue at once', () => {
  try {
    parseScenario({ banks: [{ name: '', provisioning_token: '', compartments: [] }] });
    assert.fail('expected ScenarioValidationError');
  } catch (error) {
    assert.ok(error instanceof ScenarioValidationError);
    assert.ok(error.issues.length >= 3, `expected several issues, got ${error.issues.length}`);
  }
});
