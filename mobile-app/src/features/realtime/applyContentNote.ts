import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';

import type { CompartmentNoteUpdatedPayload } from './echo';

/**
 * Patches a content-note event into the `getCompartmentsAccessible` cache draft:
 * finds the matching compartment across locker banks and updates its
 * `content_note` / `content_note_updated_at` / `content_note_updated_by_user_id`
 * in place. No-op if the compartment is not in the current cache (e.g. it is not
 * accessible to this user).
 */
export function applyContentNote(
  draft: GetCompartmentsAccessibleApiResponse,
  payload: CompartmentNoteUpdatedPayload,
): void {
  for (const bank of draft.locker_banks) {
    const compartment = bank.compartments.find((c) => c.id === payload.compartment_id);
    if (compartment) {
      compartment.content_note = payload.content_note;
      compartment.content_note_updated_at = payload.content_note_updated_at;
      compartment.content_note_updated_by_user_id = payload.content_note_updated_by_user_id;
      return;
    }
  }
}
