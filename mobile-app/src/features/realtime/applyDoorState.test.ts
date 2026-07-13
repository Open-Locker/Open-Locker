import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';

import { applyDoorState } from './applyDoorState';

function seedCache(): GetCompartmentsAccessibleApiResponse {
  return {
    status: true,
    locker_banks: [
      {
        id: 'bank-1',
        name: 'Bank 1',
        location_description: null,
        compartments: [
          { id: 'c-1', number: 1, door_state: 'closed', door_state_changed_at: null },
          { id: 'c-2', number: 2, door_state: 'unknown', door_state_changed_at: null },
        ],
      },
      {
        id: 'bank-2',
        name: 'Bank 2',
        location_description: null,
        compartments: [{ id: 'c-3', number: 1, door_state: 'closed', door_state_changed_at: null }],
      },
    ],
  };
}

describe('applyDoorState', () => {
  it('updates the matching compartment door_state and timestamp in place', () => {
    const draft = seedCache();

    applyDoorState(draft, {
      compartment_id: 'c-2',
      door_state: 'open',
      door_state_changed_at: '2026-06-19T10:00:00+00:00',
    });

    expect(draft.locker_banks[0].compartments[1]).toMatchObject({
      id: 'c-2',
      door_state: 'open',
      door_state_changed_at: '2026-06-19T10:00:00+00:00',
    });
  });

  it('matches across locker banks', () => {
    const draft = seedCache();

    applyDoorState(draft, {
      compartment_id: 'c-3',
      door_state: 'open',
      door_state_changed_at: null,
    });

    expect(draft.locker_banks[1].compartments[0].door_state).toBe('open');
  });

  it('leaves other compartments untouched', () => {
    const draft = seedCache();

    applyDoorState(draft, {
      compartment_id: 'c-1',
      door_state: 'open',
      door_state_changed_at: null,
    });

    expect(draft.locker_banks[0].compartments[1].door_state).toBe('unknown');
    expect(draft.locker_banks[1].compartments[0].door_state).toBe('closed');
  });

  it('is a no-op when the compartment is not in the cache', () => {
    const draft = seedCache();
    const before = JSON.stringify(draft);

    applyDoorState(draft, {
      compartment_id: 'missing',
      door_state: 'open',
      door_state_changed_at: null,
    });

    expect(JSON.stringify(draft)).toBe(before);
  });
});
