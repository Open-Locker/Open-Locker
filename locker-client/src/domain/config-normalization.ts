import { createHash } from 'crypto';
import type { CompartmentConfig } from './compartment';
import type { AdapterType, FeedbackType } from './config';

export function normalizeCompartments(compartments: CompartmentConfig[]): CompartmentConfig[] {
  return [...compartments]
    .map((c) => ({
      compartment_number: c.compartment_number,
      slaveId: c.slaveId,
      address: c.address,
    }))
    .toSorted((a, b) => a.compartment_number - b.compartment_number);
}

export interface CanonicalRuntimeConfig {
  adapter_type: AdapterType;
  feedback_type: FeedbackType;
  compartments: CompartmentConfig[];
}

export function canonicalizeRuntimeConfig(config: CanonicalRuntimeConfig): CanonicalRuntimeConfig {
  return {
    adapter_type: config.adapter_type,
    feedback_type: config.feedback_type,
    compartments: normalizeCompartments(config.compartments),
  };
}

export function computeAppliedConfigHash(config: CanonicalRuntimeConfig): string {
  return createHash('sha256')
    .update(JSON.stringify(canonicalizeRuntimeConfig(config)))
    .digest('hex');
}
