import type { CompartmentConfig } from '../../src/domain/compartment';
import type { AdapterType, FeedbackType } from '../../src/domain/config';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';

export function canonicalConfigHash(
  compartments: CompartmentConfig[],
  adapterType: AdapterType = 'rs485_lock_board',
  feedbackType: FeedbackType = 'door_closing',
): string {
  return computeAppliedConfigHash({
    adapter_type: adapterType,
    feedback_type: feedbackType,
    compartments,
  });
}
