import { renderHook } from '@testing-library/react-native';

import { realtimeConnectionChanged, realtimeReset } from '@/src/store/realtimeSlice';

import { useCompartmentStatusRealtime } from './useCompartmentStatusRealtime';

const mockDispatch = jest.fn();
const calls: string[] = [];
const handlers = new Map<string, (change?: { current: string }) => void>();

const mockConnection = {
  state: 'connecting',
  bind: (event: string, handler: (change?: { current: string }) => void) => {
    handlers.set(event, handler);
  },
  unbind: (event: string) => {
    calls.push(`unbind:${event}`);
    handlers.delete(event);
  },
};

const mockChannel = { listen: () => mockChannel };

const mockRealtime = { key: 'k', host: 'ws.locker.example.org', port: 443, scheme: 'https' };
const mockIdentify: { data?: unknown; isLoading: boolean } = { isLoading: false };
const mockCreateEcho = jest.fn();

jest.mock('./echo', () => ({
  createEcho: (...args: unknown[]) => {
    mockCreateEcho(...args);
    return {
      private: () => mockChannel,
      leave: () => undefined,
      disconnect: () => calls.push('disconnect'),
      connector: { pusher: { connection: mockConnection } },
    };
  },
}));

jest.mock('@/src/store/generatedApi', () => ({
  openLockerApi: { util: { updateQueryData: jest.fn(), invalidateTags: jest.fn() } },
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

  it('shows live updates as paused instead of guessing a socket when none is advertised', async () => {
    mockIdentify.data = { realtime: null };

    await renderHook(() => useCompartmentStatusRealtime());

    expect({
      connected: mockCreateEcho.mock.calls.length,
      dispatched: mockDispatch.mock.calls.at(-1)?.[0],
    }).toEqual({ connected: 0, dispatched: realtimeConnectionChanged('unavailable') });
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
});
