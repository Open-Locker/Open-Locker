import {
  currentOpenProgress,
  isOpenFinished,
  isOpenStateAdvance,
  NO_OPEN_PROBLEMS,
  readCommandId,
  tallyOpenOutcome,
  toOpenProgress,
} from './openProgress';

describe('tallyOpenOutcome', () => {
  it('counts each failed request', () => {
    const afterFirst = tallyOpenOutcome(NO_OPEN_PROBLEMS, 'cmd-1', 'jammed');

    expect(tallyOpenOutcome(afterFirst, 'cmd-2', 'noResponse').count).toBe(2);
  });

  it('counts a request once when it reports a second problem', () => {
    const timedOut = tallyOpenOutcome(NO_OPEN_PROBLEMS, 'cmd-1', 'noResponse');

    expect(tallyOpenOutcome(timedOut, 'cmd-1', 'jammed').count).toBe(1);
  });

  it('keeps the count while a retry is in flight', () => {
    const afterFirst = tallyOpenOutcome(NO_OPEN_PROBLEMS, 'cmd-1', 'jammed');

    expect(tallyOpenOutcome(afterFirst, 'cmd-2', 'sending').count).toBe(1);
  });

  it('resets once the door opens', () => {
    const afterFirst = tallyOpenOutcome(NO_OPEN_PROBLEMS, 'cmd-1', 'jammed');

    expect(tallyOpenOutcome(afterFirst, 'cmd-2', 'opened').count).toBe(0);
  });
});

describe('isOpenStateAdvance', () => {
  it('accepts a later step', () => {
    expect(isOpenStateAdvance('sent', 'acknowledged')).toBe(true);
  });

  it('rejects an earlier step, such as a slow poll after a realtime event', () => {
    expect(isOpenStateAdvance('acknowledged', 'sent')).toBe(false);
  });

  it('never changes a finished request', () => {
    expect(isOpenStateAdvance('door_jammed', 'acknowledged')).toBe(false);
  });
});

describe('toOpenProgress', () => {
  it.each([
    ['pending', 'sending'],
    ['accepted', 'sending'],
    ['sent', 'sending'],
    ['acknowledged', 'unlocking'],
    ['opened', 'opened'],
    ['already_open', 'alreadyOpen'],
    ['door_jammed', 'jammed'],
    ['failed', 'failed'],
    ['denied', 'denied'],
  ])('maps backend state %s to %s', (state, progress) => {
    expect(toOpenProgress(state)).toBe(progress);
  });

  it('treats an unknown state as still in flight', () => {
    expect(toOpenProgress('something_new')).toBe('sending');
  });
});

describe('isOpenFinished', () => {
  it('is false while the locker is still working', () => {
    expect([isOpenFinished('sending'), isOpenFinished('unlocking')]).toEqual([false, false]);
  });

  it('is true for every outcome', () => {
    expect(
      (['opened', 'alreadyOpen', 'jammed', 'failed', 'denied', 'noResponse'] as const).every(
        isOpenFinished,
      ),
    ).toBe(true);
  });
});

describe('currentOpenProgress', () => {
  it('starts as sending before the first status arrives', () => {
    expect(currentOpenProgress(undefined, false)).toBe('sending');
  });

  it('turns an unfinished request into noResponse once timed out', () => {
    expect(currentOpenProgress('acknowledged', true)).toBe('noResponse');
  });

  it('keeps a reported outcome after the timeout', () => {
    expect(currentOpenProgress('door_jammed', true)).toBe('jammed');
  });
});

describe('readCommandId', () => {
  it('reads command_id from the 202 body', () => {
    expect(readCommandId({ status: true, command_id: 'cmd-1', state: 'pending' })).toBe('cmd-1');
  });

  it('returns null when the body has no usable command_id', () => {
    expect([readCommandId(202), readCommandId(null), readCommandId({ command_id: '' })]).toEqual([
      null,
      null,
      null,
    ]);
  });
});
