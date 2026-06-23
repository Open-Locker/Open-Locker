import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';

import type { CompartmentDoorStateUpdatedPayload } from './echo';

/**
 * Patches a door-state event into the `getCompartmentsAccessible` cache draft:
 * finds the matching compartment across locker banks and updates its
 * `door_state` / `door_state_changed_at` in place. No-op if the compartment is
 * not in the current cache (e.g. it is not accessible to this user).
 */
export function applyDoorState(
  draft: GetCompartmentsAccessibleApiResponse,
  payload: CompartmentDoorStateUpdatedPayload,
): void {
  for (const bank of draft.locker_banks) {
    const compartment = bank.compartments.find((c) => c.id === payload.compartment_id);
    if (compartment) {
      compartment.door_state = payload.door_state;
      compartment.door_state_changed_at = payload.door_state_changed_at;
      return;
    }
  }
}
