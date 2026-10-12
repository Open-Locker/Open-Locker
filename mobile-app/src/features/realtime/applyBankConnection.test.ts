import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';

import { applyBankConnection } from './applyBankConnection';

function seedCache(): GetCompartmentsAccessibleApiResponse {
  return {
    status: true,
    locker_banks: [
      {
        id: 'bank-1',
        name: 'Bank 1',
        location_description: null,
        support_phone: null,
        connection_status: 'online',
        compartments: [],
      },
      {
        id: 'bank-2',
        name: 'Bank 2',
        location_description: null,
        support_phone: null,
        connection_status: 'unknown',
        compartments: [],
      },
    ],
  };
}

describe('applyBankConnection', () => {
  it('marks the named bank offline without touching the others', () => {
    const draft = seedCache();

    applyBankConnection(draft, {
      locker_bank_id: 'bank-1',
      connection_status: 'offline',
      connection_status_changed_at: '2026-09-16T10:00:00+00:00',
      last_heartbeat_at: '2026-09-16T09:59:00+00:00',
    });

    expect(draft.locker_banks[0].connection_status).toBe('offline');
    expect(draft.locker_banks[1].connection_status).toBe('unknown');
  });

  it('brings a bank back online', () => {
    const draft = seedCache();
    draft.locker_banks[0].connection_status = 'offline';

    applyBankConnection(draft, {
      locker_bank_id: 'bank-1',
      connection_status: 'online',
      connection_status_changed_at: null,
      last_heartbeat_at: null,
    });

    expect(draft.locker_banks[0].connection_status).toBe('online');
  });

  it('is a no-op for a bank the user cannot see', () => {
    const draft = seedCache();
    const before = JSON.stringify(draft);

    applyBankConnection(draft, {
      locker_bank_id: 'bank-missing',
      connection_status: 'offline',
      connection_status_changed_at: null,
      last_heartbeat_at: null,
    });

    expect(JSON.stringify(draft)).toBe(before);
  });
});
