import { renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';

import {
  realtimeConnectionChanged,
  realtimeReducer,
  realtimeReset,
  selectLivePaused,
} from '@/src/store/realtimeSlice';

import { useCompartmentStatusRealtime } from './useCompartmentStatusRealtime';

const mockDispatch = jest.fn();
const calls: string[] = [];
const handlers = new Map<string, (payload?: unknown) => void>();

const mockConnection = {
  state: 'connecting',
  bind: (event: string, handler: (payload?: unknown) => void) => {
    handlers.set(event, handler);
  },
  unbind: (event: string) => {
    calls.push(`unbind:${event}`);
    handlers.delete(event);
  },
};

const channelErrorHandlers = new Map<string, (error: unknown) => void>();
const channelSubscribedHandlers = new Map<string, () => void>();

function mockChannel(name: string) {
  const channel = {
    listen: () => channel,
    subscribed: (handler: () => void) => {
      channelSubscribedHandlers.set(name, handler);
      return channel;
    },
    error: (handler: (error: unknown) => void) => {
      channelErrorHandlers.set(name, handler);
      return channel;
    },
  };
  return channel;
}

/** What the paused notice would show after everything the hook dispatched. */
function livePausedAfterDispatches(): boolean {
  let state = realtimeReducer(undefined, { type: '@@init' });
  for (const [action] of mockDispatch.mock.calls) {
    if (typeof action?.type === 'string' && action.type.startsWith('realtime/')) {
      state = realtimeReducer(state, action);
    }
  }
  return selectLivePaused({ realtime: state });
}

/** The action the mocked `invalidateTags` returns: the hook's REST refetch. */
const restRefetch = { type: 'test/restRefetch', tags: ['Compartment', 'Auth'] };

let appStateListener: ((state: AppStateStatus) => void) | undefined;
function returnToForeground() {
  appStateListener?.('active');
}

const mockRealtime = { key: 'k', host: 'ws.locker.example.org', port: 443, scheme: 'https' };
const mockIdentify: { data?: unknown; isLoading: boolean } = { isLoading: false };
const mockCreateEcho = jest.fn();

jest.mock('./echo', () => ({
  createEcho: (...args: unknown[]) => {
    mockCreateEcho(...args);
    return {
      private: (name: string) => mockChannel(name),
      leave: () => undefined,
      disconnect: () => calls.push('disconnect'),
      connector: { pusher: { connection: mockConnection } },
    };
  },
}));

jest.mock('@/src/store/generatedApi', () => ({
  openLockerApi: {
    util: {
      updateQueryData: jest.fn(),
      invalidateTags: (tags: string[]) => ({ type: 'test/restRefetch', tags }),
    },
  },
  useGetUserQuery: () => ({ data: { id: 7 } }),
  useIdentifyQuery: () => mockIdentify,
}));

jest.mock('@/src/store/hooks', () => ({
  useAppDispatch: () => mockDispatch,
  useAppSelector: () => 'token',
}));

describe('useCompartmentStatusRealtime', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    calls.length = 0;
    handlers.clear();
    channelErrorHandlers.clear();
    channelSubscribedHandlers.clear();
    appStateListener = undefined;
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, listener) => {
      appStateListener = listener;
      return { remove: () => (appStateListener = undefined) };
    });
    mockConnection.state = 'connecting';
    mockIdentify.data = { realtime: mockRealtime };
    mockIdentify.isLoading = false;
  });

  it('waits for the server to say where its Reverb runs before connecting', async () => {
    mockIdentify.isLoading = true;

    await renderHook(() => useCompartmentStatusRealtime());

    expect(mockCreateEcho).not.toHaveBeenCalled();
  });

  it('connects to the Reverb server the selected server advertises', async () => {
    await renderHook(() => useCompartmentStatusRealtime());

    expect(mockCreateEcho).toHaveBeenCalledWith('token', mockRealtime);
  });

  it('shows live updates as paused when the server advertises no Reverb', async () => {
    mockIdentify.data = { realtime: null };

    await renderHook(() => useCompartmentStatusRealtime());

    expect({
      connected: mockCreateEcho.mock.calls.length,
      dispatched: mockDispatch.mock.calls.at(-1)?.[0],
    }).toEqual({ connected: 0, dispatched: realtimeConnectionChanged('unavailable') });
  });

  it('refetches on returning to the foreground when the server advertises no Reverb', async () => {
    mockIdentify.data = { realtime: null };
    await renderHook(() => useCompartmentStatusRealtime());

    returnToForeground();

    expect(mockDispatch).toHaveBeenCalledWith(restRefetch);
  });

  it('picks up a state the socket reached before the listener was bound', async () => {
    mockConnection.state = 'failed';

    await renderHook(() => useCompartmentStatusRealtime());

    expect(mockDispatch).toHaveBeenCalledWith(realtimeConnectionChanged('failed'));
  });

  it('mirrors socket state changes into the realtime slice', async () => {
    await renderHook(() => useCompartmentStatusRealtime());

    handlers.get('state_change')?.({ current: 'unavailable' });

    expect(mockDispatch).toHaveBeenCalledWith(realtimeConnectionChanged('unavailable'));
  });

  it('stops listening before disconnecting, then clears the paused flag', async () => {
    // Disconnecting while still bound would report a logout as paused live updates.
    const { unmount } = await renderHook(() => useCompartmentStatusRealtime());

    await unmount();

    expect({
      order: calls.slice(calls.indexOf('unbind:state_change')),
      lastDispatch: mockDispatch.mock.calls.at(-1)?.[0],
    }).toEqual({ order: ['unbind:state_change', 'disconnect'], lastDispatch: realtimeReset() });
  });

  // Both cases were reproduced against a real Reverb with pusher-js: the
  // connection stays `connected` while the channel never subscribes.
  it.each(['users.7.compartment-status', 'users.7.account', 'users.7.locker-banks'])(
    'shows live updates as paused when the auth request for %s fails',
    async (channel) => {
      await renderHook(() => useCompartmentStatusRealtime());
      handlers.get('state_change')?.({ current: 'connected' });

      // What pusher-js emits when /broadcasting/auth answers 401 (expired token).
      channelErrorHandlers.get(channel)?.({
        type: 'AuthError',
        error: 'broadcasting/auth failed: 401',
        status: 401,
      });

      expect(livePausedAfterDispatches()).toBe(true);
      expect(mockDispatch).toHaveBeenCalledWith(restRefetch);
    },
  );

  it('shows live updates as paused when Reverb rejects the channel signature', async () => {
    await renderHook(() => useCompartmentStatusRealtime());
    handlers.get('state_change')?.({ current: 'connected' });

    // What Reverb sends when the API signs with another REVERB_APP_SECRET.
    handlers.get('error')?.({
      type: 'PusherError',
      data: { code: 4009, message: 'Connection is unauthorized' },
    });

    expect(livePausedAfterDispatches()).toBe(true);
    expect(mockDispatch).toHaveBeenCalledWith(restRefetch);
  });

  it('stays paused after a 4009 until every channel has subscribed', async () => {
    await renderHook(() => useCompartmentStatusRealtime());
    handlers.get('state_change')?.({ current: 'connected' });
    handlers.get('error')?.({ type: 'PusherError', data: { code: 4009 } });

    channelSubscribedHandlers.get('users.7.compartment-status')?.();
    const pausedAfterOne = livePausedAfterDispatches();
    channelSubscribedHandlers.get('users.7.account')?.();
    channelSubscribedHandlers.get('users.7.locker-banks')?.();

    expect({ pausedAfterOne, pausedAfterAll: livePausedAfterDispatches() }).toEqual({
      pausedAfterOne: true,
      pausedAfterAll: false,
    });
  });
});
