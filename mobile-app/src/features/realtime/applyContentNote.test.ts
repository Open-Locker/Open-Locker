import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';

import { applyContentNote } from './applyContentNote';

function seedCache(): GetCompartmentsAccessibleApiResponse {
  return {
    status: true,
    locker_banks: [
      {
        id: 'bank-1',
        name: 'Bank 1',
        location_description: null,
        compartments: [
          {
            id: 'c-1',
            number: 1,
            door_state: 'closed',
            door_state_changed_at: null,
            content_note: null,
            content_note_updated_at: null,
            content_note_updated_by_user_id: null,
          },
          {
            id: 'c-2',
            number: 2,
            door_state: 'unknown',
            door_state_changed_at: null,
            content_note: 'old note',
            content_note_updated_at: '2026-06-01T00:00:00+00:00',
            content_note_updated_by_user_id: '7',
          },
        ],
      },
      {
        id: 'bank-2',
        name: 'Bank 2',
        location_description: null,
        compartments: [
          {
            id: 'c-3',
            number: 1,
            door_state: 'closed',
            door_state_changed_at: null,
            content_note: null,
            content_note_updated_at: null,
            content_note_updated_by_user_id: null,
          },
        ],
      },
    ],
  };
}

describe('applyContentNote', () => {
  it('updates the matching compartment note fields in place', () => {
    const draft = seedCache();

    applyContentNote(draft, {
      compartment_id: 'c-2',
      content_note: 'new note',
      content_note_updated_at: '2026-06-24T10:00:00+00:00',
      content_note_updated_by_user_id: '9',
    });

    expect(draft.locker_banks[0].compartments[1]).toMatchObject({
      id: 'c-2',
      content_note: 'new note',
      content_note_updated_at: '2026-06-24T10:00:00+00:00',
      content_note_updated_by_user_id: '9',
    });
  });

  it('clears the note when set to null', () => {
    const draft = seedCache();

    applyContentNote(draft, {
      compartment_id: 'c-2',
      content_note: null,
      content_note_updated_at: '2026-06-24T10:00:00+00:00',
      content_note_updated_by_user_id: '9',
    });

    expect(draft.locker_banks[0].compartments[1].content_note).toBeNull();
  });

  it('matches across locker banks', () => {
    const draft = seedCache();

    applyContentNote(draft, {
      compartment_id: 'c-3',
      content_note: 'cross-bank',
      content_note_updated_at: null,
      content_note_updated_by_user_id: null,
    });

    expect(draft.locker_banks[1].compartments[0].content_note).toBe('cross-bank');
  });

  it('is a no-op when the compartment is not in the cache', () => {
    const draft = seedCache();
    const before = JSON.stringify(draft);

    applyContentNote(draft, {
      compartment_id: 'missing',
      content_note: 'nope',
      content_note_updated_at: null,
      content_note_updated_by_user_id: null,
    });

    expect(JSON.stringify(draft)).toBe(before);
  });
});
