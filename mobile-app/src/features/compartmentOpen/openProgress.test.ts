import {
  currentOpenProgress,
  isOpenFinished,
  nextProblemCount,
  readCommandId,
  toOpenProgress,
  toTelUrl,
} from './openProgress';

describe('nextProblemCount', () => {
  it('counts each failed attempt', () => {
    expect(nextProblemCount(nextProblemCount(0, 'jammed'), 'noResponse')).toBe(2);
  });

  it('keeps the count while a retry is in flight', () => {
    expect(nextProblemCount(1, 'sending')).toBe(1);
  });

  it('resets once the door opens', () => {
    expect(nextProblemCount(2, 'opened')).toBe(0);
  });
});

describe('toTelUrl', () => {
  it('keeps only digits and a leading plus', () => {
    expect(toTelUrl('+49 (30) 123-45 67')).toBe('tel:+49301234567');
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
