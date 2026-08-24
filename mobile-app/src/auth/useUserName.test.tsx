import { renderHook } from '@testing-library/react-native';
import { useUserName } from './useUserName';

const mockUseGetUserQuery = jest.fn();
const mockUseAppSelector = jest.fn();

jest.mock('@/src/store/generatedApi', () => ({
  useGetUserQuery: () => mockUseGetUserQuery(),
}));

jest.mock('@/src/store/hooks', () => ({
  useAppSelector: (selector: unknown) => mockUseAppSelector(selector),
}));

describe('useUserName', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('prefers the name the API returns over the one stored at login', () => {
    // The bug this replaces: the account screen showed the login-time name while
    // its own input field showed the updated one.
    mockUseAppSelector.mockReturnValue('Old Name');
    mockUseGetUserQuery.mockReturnValue({ data: { first_name: 'New', last_name: 'Name' } });

    const { result } = renderHook(() => useUserName());

    expect(result.current).toBe('New Name');
  });

  it('falls back to the stored name until the query resolves', () => {
    // On a cold start there is no query result yet. Showing nothing would trade a
    // stale name for a blank one.
    mockUseAppSelector.mockReturnValue('Stored Name');
    mockUseGetUserQuery.mockReturnValue({ data: undefined });

    const { result } = renderHook(() => useUserName());

    expect(result.current).toBe('Stored Name');
  });

  it('reports no name rather than an empty string when the user has none', () => {
    mockUseAppSelector.mockReturnValue('Stored Name');
    mockUseGetUserQuery.mockReturnValue({ data: { first_name: '', last_name: null } });

    const { result } = renderHook(() => useUserName());

    expect(result.current).toBeNull();
  });
});
