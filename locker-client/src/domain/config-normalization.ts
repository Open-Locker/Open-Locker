import { createHash } from 'crypto';
import type { CompartmentConfig } from './compartment';
import type { AdapterType, ChannelCount, FeedbackType } from './config';

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
  channel_count: ChannelCount;
  feedback_type: FeedbackType;
  compartments: CompartmentConfig[];
}

export function canonicalizeRuntimeConfig(config: CanonicalRuntimeConfig): CanonicalRuntimeConfig {
  return {
    adapter_type: config.adapter_type,
    channel_count: config.channel_count,
    feedback_type: config.feedback_type,
    compartments: normalizeCompartments(config.compartments),
  };
}

export function computeAppliedConfigHash(
  config: CanonicalRuntimeConfig | CompartmentConfig[],
): string {
  const canonical = Array.isArray(config)
    ? {
        adapter_type: 'waveshare_modbus' as const,
        channel_count: 8 as const,
        feedback_type: 'door_closing' as const,
        compartments: config,
      }
    : config;
  return createHash('sha256')
    .update(JSON.stringify(canonicalizeRuntimeConfig(canonical)))
    .digest('hex');
}
