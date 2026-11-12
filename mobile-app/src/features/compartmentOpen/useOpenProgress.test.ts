import { renderHook } from '@testing-library/react-native';
import { useOpenProgress } from './useOpenProgress';

const mockUseQueryState = jest.fn();

jest.mock('@/src/store/generatedApi', () => ({
  openLockerApi: {
    endpoints: {
      getCompartmentsOpenRequestsByCommandId: {
        useQueryState: () => mockUseQueryState(),
      },
    },
  },
  useGetCompartmentsOpenRequestsByCommandIdQuery: jest.fn(),
}));

function reportState(state: string | undefined) {
  mockUseQueryState.mockReturnValue({ data: state === undefined ? undefined : { state } });
}

describe('useOpenProgress', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('follows the reported state forward', async () => {
    reportState('sent');
    const { result, rerender } = await renderHook(() => useOpenProgress('cmd-1'));
    expect(result.current).toBe('sending');

    reportState('opened');
    await rerender({});
    expect(result.current).toBe('opened');
  });

  it('keeps the furthest state when an older poll lands after a newer event', async () => {
    reportState('acknowledged');
    const { result, rerender } = await renderHook(() => useOpenProgress('cmd-1'));
    expect(result.current).toBe('unlocking');

    reportState('sent');
    await rerender({});
    expect(result.current).toBe('unlocking');
  });

  it('starts over for a new command', async () => {
    reportState('door_jammed');
    const { result, rerender } = await renderHook(
      ({ commandId }: { commandId: string | null }) => useOpenProgress(commandId),
      { initialProps: { commandId: 'cmd-1' } },
    );
    expect(result.current).toBe('jammed');

    reportState('sent');
    await rerender({ commandId: 'cmd-2' });
    expect(result.current).toBe('sending');
  });

  it('reports nothing without a command', async () => {
    reportState(undefined);
    const { result } = await renderHook(() => useOpenProgress(null));
    expect(result.current).toBeNull();
  });
});
