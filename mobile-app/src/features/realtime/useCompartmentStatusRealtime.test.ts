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

jest.mock('./echo', () => ({
  createEcho: () => ({
    private: () => mockChannel,
    leave: () => undefined,
    disconnect: () => calls.push('disconnect'),
    connector: { pusher: { connection: mockConnection } },
  }),
}));

jest.mock('@/src/store/generatedApi', () => ({
  openLockerApi: { util: { updateQueryData: jest.fn(), invalidateTags: jest.fn() } },
  useGetUserQuery: () => ({ data: { id: 7 } }),
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
  });

  it('picks up a state the socket reached before the listener was bound', () => {
    mockConnection.state = 'failed';

    renderHook(() => useCompartmentStatusRealtime());

    expect(mockDispatch).toHaveBeenCalledWith(realtimeConnectionChanged('failed'));
  });

  it('mirrors socket state changes into the realtime slice', () => {
    renderHook(() => useCompartmentStatusRealtime());

    handlers.get('state_change')?.({ current: 'unavailable' });

    expect(mockDispatch).toHaveBeenCalledWith(realtimeConnectionChanged('unavailable'));
  });

  it('stops listening before disconnecting, then clears the paused flag', () => {
    // Disconnecting while still bound would report a logout as paused live updates.
    const { unmount } = renderHook(() => useCompartmentStatusRealtime());

    unmount();

    expect({
      order: calls.slice(calls.indexOf('unbind:state_change')),
      lastDispatch: mockDispatch.mock.calls.at(-1)?.[0],
    }).toEqual({ order: ['unbind:state_change', 'disconnect'], lastDispatch: realtimeReset() });
  });
});
