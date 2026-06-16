import { createHash } from 'crypto';
import type { CompartmentConfig } from './compartment';

export function normalizeCompartments(compartments: CompartmentConfig[]): CompartmentConfig[] {
  return [...compartments]
    .map((c) => ({
      compartment_number: c.compartment_number,
      slaveId: c.slaveId,
      address: c.address,
    }))
    .toSorted((a, b) => a.compartment_number - b.compartment_number);
}

export function computeAppliedConfigHash(compartments: CompartmentConfig[]): string {
  return createHash('sha256')
    .update(JSON.stringify(normalizeCompartments(compartments)))
    .digest('hex');
}
