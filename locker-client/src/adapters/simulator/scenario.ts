import fs from 'fs';
import { load } from 'js-yaml';
import { z } from 'zod';
import {
  DEFAULT_FLASH_DURATION_MS,
  MAX_FLASH_DURATION_MS,
  MIN_FLASH_DURATION_MS,
} from '../../domain/compartment';

const MAX_RELAY_ADDRESS = 7;

/**
 * Scenario file for the fleet simulator (ADR-0027, Decision 6).
 *
 * Mirrors `locker-config.yml` conventions so a scenario reads like a locker
 * config: which banks to emulate (by provisioning token), their compartment
 * mapping, and the door state each compartment starts in. Keeping scenarios in
 * YAML makes multi-bank setups repeatable and reviewable, rather than a pile of
 * CLI flags.
 */
const doorStateSchema = z.enum(['open', 'closed', 'unknown']);

const compartmentSchema = z.object({
  compartment_number: z.number().int().positive(),
  slaveId: z.number().int().positive(),
  address: z.number().int().min(0).max(MAX_RELAY_ADDRESS),
  door_state: doorStateSchema.default('closed'),
});

const bankSchema = z.object({
  /** Label used in logs and interactive commands; not part of the contract. */
  name: z.string().min(1),
  provisioning_token: z.string().min(1),
  heartbeat_interval_seconds: z.number().int().positive().default(15),
  flash_duration_ms: z
    .number()
    .int()
    .min(MIN_FLASH_DURATION_MS)
    .max(MAX_FLASH_DURATION_MS)
    .default(DEFAULT_FLASH_DURATION_MS),
  /** Simulated bus round-trip delay, for exercising slow-hardware behaviour. */
  latency_ms: z.number().int().min(0).default(0),
  compartments: z.array(compartmentSchema).min(1),
});

const simulatorScenarioSchema = z.object({
  broker_url: z.string().min(1).optional(),
  banks: z.array(bankSchema).min(1),
});

export type SimulatorScenario = z.infer<typeof simulatorScenarioSchema>;
export type SimulatorBankScenario = z.infer<typeof bankSchema>;

export class ScenarioValidationError extends Error {
  constructor(
    message: string,
    readonly issues: string[] = [],
  ) {
    super(message);
    this.name = 'ScenarioValidationError';
  }
}

export function parseScenario(raw: unknown): SimulatorScenario {
  const parsed = simulatorScenarioSchema.safeParse(raw);

  if (!parsed.success) {
    throw new ScenarioValidationError(
      'Simulator scenario is invalid',
      parsed.error.issues.map((issue) => `${issue.path.join('.') || '(root)'}: ${issue.message}`),
    );
  }

  assertUniqueBankTokens(parsed.data);
  for (const bank of parsed.data.banks) {
    assertUniqueCompartmentTargets(bank);
  }

  return parsed.data;
}

export function loadScenario(scenarioFilePath: string): SimulatorScenario {
  if (!fs.existsSync(scenarioFilePath)) {
    throw new ScenarioValidationError(`Simulator scenario file not found: ${scenarioFilePath}`);
  }

  return parseScenario(load(fs.readFileSync(scenarioFilePath, 'utf8')));
}

/**
 * Two devices sharing a provisioning token would end up as the same locker UUID,
 * and the broker drops one of them on session takeover — a confusing failure to
 * debug, so reject it up front.
 */
function assertUniqueBankTokens(scenario: SimulatorScenario): void {
  const seen = new Set<string>();

  for (const bank of scenario.banks) {
    if (seen.has(bank.provisioning_token)) {
      throw new ScenarioValidationError(
        `Duplicate provisioning_token across banks (${bank.name}); each simulated bank needs its own token`,
      );
    }
    seen.add(bank.provisioning_token);
  }
}

/** Same rules the real `apply_config` path enforces, caught before startup. */
function assertUniqueCompartmentTargets(bank: SimulatorBankScenario): void {
  const seenNumbers = new Set<number>();
  const seenTargets = new Set<string>();

  for (const compartment of bank.compartments) {
    if (seenNumbers.has(compartment.compartment_number)) {
      throw new ScenarioValidationError(
        `Bank "${bank.name}" has duplicate compartment_number ${compartment.compartment_number}`,
      );
    }
    seenNumbers.add(compartment.compartment_number);

    const target = `${compartment.slaveId}:${compartment.address}`;
    if (seenTargets.has(target)) {
      throw new ScenarioValidationError(`Bank "${bank.name}" has duplicate relay target ${target}`);
    }
    seenTargets.add(target);
  }
}
