import {
  isConnectionPaused,
  realtimeConnectionChanged,
  realtimeReducer,
  realtimeSubscriptionFailed,
  realtimeSubscriptionSucceeded,
  selectLivePaused,
} from './realtimeSlice';

function livePausedAfter(...actions: Parameters<typeof realtimeReducer>[1][]): boolean {
  const realtime = actions.reduce(realtimeReducer, realtimeReducer(undefined, { type: '@@init' }));
  return selectLivePaused({ realtime });
}

describe('isConnectionPaused', () => {
  it('pauses when the socket drops', () => {
    expect(
      (['unavailable', 'failed', 'disconnected'] as const).map((s) => isConnectionPaused(false, s)),
    ).toEqual([true, true, true]);
  });

  it('resumes once connected', () => {
    expect(isConnectionPaused(true, 'connected')).toBe(false);
  });

  it('keeps the previous answer while connecting', () => {
    expect([
      isConnectionPaused(false, 'connecting'),
      isConnectionPaused(true, 'connecting'),
    ]).toEqual([false, true]);
  });
});

describe('selectLivePaused', () => {
  const connected = realtimeConnectionChanged('connected');

  it('pauses while a channel subscription is refused on a connected socket', () => {
    expect(livePausedAfter(connected, realtimeSubscriptionFailed('users.7.account'))).toBe(true);
  });

  it('resumes once every refused channel has subscribed', () => {
    expect(
      livePausedAfter(
        connected,
        realtimeSubscriptionFailed('a'),
        realtimeSubscriptionFailed('b'),
        realtimeSubscriptionSucceeded('a'),
      ),
    ).toBe(true);
    expect(
      livePausedAfter(
        connected,
        realtimeSubscriptionFailed('a'),
        realtimeSubscriptionSucceeded('a'),
      ),
    ).toBe(false);
  });

  it('forgets refusals on reconnect, when every channel is subscribed again', () => {
    expect(
      livePausedAfter(
        connected,
        realtimeSubscriptionFailed('a'),
        realtimeConnectionChanged('disconnected'),
        connected,
      ),
    ).toBe(false);
  });
});
