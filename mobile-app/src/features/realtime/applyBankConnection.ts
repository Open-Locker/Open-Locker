import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';

import type { LockerBankConnectionUpdatedPayload } from './echo';

/**
 * Patches a bank's connection status into the cached locker list (one per organization, `getOrganizationCompartments`)
 * draft. No-op if the bank is not in the current cache (e.g. none of its
 * compartments are accessible to this user).
 */
export function applyBankConnection(
  draft: GetCompartmentsAccessibleApiResponse,
  payload: LockerBankConnectionUpdatedPayload,
): void {
  const bank = draft.locker_banks.find((candidate) => candidate.id === payload.locker_bank_id);

  if (bank) {
    bank.connection_status = payload.connection_status;
  }
}
