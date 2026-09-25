import type { CompartmentOpenStatus } from '@/src/store/generatedApi';

import { applyOpenStatus } from './applyOpenStatus';
import type { CompartmentOpenStatusUpdatedPayload } from './echo';

function seedStatus(state: string): CompartmentOpenStatus {
  return { status: true, command_id: 'cmd-1', state, error_code: null, error_message: null };
}

function event(
  status: string,
  overrides: Partial<CompartmentOpenStatusUpdatedPayload> = {},
): CompartmentOpenStatusUpdatedPayload {
  return {
    command_id: 'cmd-1',
    compartment_id: 'c-1',
    status,
    error_code: null,
    message: null,
    compartment_number: 1,
    locker_name: 'Bank 1',
    ...overrides,
  };
}

describe('applyOpenStatus', () => {
  it('advances the cached state to a later step', () => {
    const draft = seedStatus('sent');

    applyOpenStatus(draft, event('acknowledged'));

    expect(draft.state).toBe('acknowledged');
  });

  it('records the error detail of a failed outcome', () => {
    const draft = seedStatus('acknowledged');

    applyOpenStatus(draft, event('door_jammed', { error_code: 'NOT_DETECTED', message: 'nope' }));

    expect(draft).toMatchObject({
      state: 'door_jammed',
      error_code: 'NOT_DETECTED',
      error_message: 'nope',
    });
  });

  it('ignores an event for an earlier step that arrives late', () => {
    const draft = seedStatus('acknowledged');

    applyOpenStatus(draft, event('sent'));

    expect(draft.state).toBe('acknowledged');
  });

  it('never changes a finished request', () => {
    const draft = seedStatus('opened');

    applyOpenStatus(draft, event('failed'));

    expect(draft.state).toBe('opened');
  });
});
