import { LoginResponseError } from '@/src/auth/loginSession';
import { setItem } from '@/src/auth/storage';
import { persistAuth } from '@/src/store/authStorage';

jest.mock('@/src/auth/storage', () => ({
  setItem: jest.fn(() => Promise.resolve()),
  getItem: jest.fn(),
  deleteItem: jest.fn(),
}));

const mockedSetItem = jest.mocked(setItem);

describe('persistAuth', () => {
  beforeEach(() => {
    mockedSetItem.mockClear();
  });

  it('stores the token and display name as strings', async () => {
    await persistAuth('1|token', 'Ada Lovelace');

    expect(mockedSetItem).toHaveBeenCalledWith(expect.any(String), '1|token');
    expect(mockedSetItem).toHaveBeenCalledWith(expect.any(String), 'Ada Lovelace');
  });

  it('stores an empty display name', async () => {
    await persistAuth('1|token', '');

    expect(mockedSetItem).toHaveBeenCalledWith(expect.any(String), '');
  });

  it('does not touch SecureStore when the token is missing', async () => {
    await expect(persistAuth(undefined as unknown as string, 'Ada')).rejects.toBeInstanceOf(
      LoginResponseError,
    );

    expect(mockedSetItem).not.toHaveBeenCalled();
  });
});
