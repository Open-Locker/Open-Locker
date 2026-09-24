import { isOpenStateAdvance } from '@/src/features/compartmentOpen/openProgress';
import type { CompartmentOpenStatus } from '@/src/store/generatedApi';

import type { CompartmentOpenStatusUpdatedPayload } from './echo';

/**
 * Patches an open-status event into the `getCompartmentsOpenRequestsByCommandId`
 * cache draft. The broadcast reactor is queued, so events can arrive out of
 * order: a step behind the cached one is ignored, and a finished request is
 * never reopened.
 */
export function applyOpenStatus(
  draft: CompartmentOpenStatus,
  payload: CompartmentOpenStatusUpdatedPayload,
): void {
  if (!isOpenStateAdvance(draft.state, payload.status)) return;

  draft.state = payload.status;
  draft.error_code = payload.error_code;
  draft.error_message = payload.message;
}
