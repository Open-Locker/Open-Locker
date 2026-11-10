import { LoginResponseError } from '@/src/auth/loginSession';
import { deleteItem, getItem, setItem } from '@/src/auth/storage';

export class SessionStoreError extends Error {
  constructor() {
    super('Could not store the sign-in session.');
    this.name = 'SessionStoreError';
  }
}

const TOKEN_KEY = 'open-locker.token';
const USERNAME_KEY = 'open-locker.userName';

export async function loadPersistedAuth() {
  const [token, userName] = await Promise.all([getItem(TOKEN_KEY), getItem(USERNAME_KEY)]);
  return { token, userName };
}

export async function persistAuth(token: string, userName: string): Promise<void> {
  if (typeof token !== 'string' || token.length === 0 || typeof userName !== 'string') {
    throw new LoginResponseError();
  }

  try {
    await Promise.all([setItem(TOKEN_KEY, token), setItem(USERNAME_KEY, userName)]);
  } catch (error) {
    if (error instanceof LoginResponseError) {
      throw error;
    }

    throw new SessionStoreError();
  }
}

export async function clearPersistedAuth(): Promise<void> {
  await Promise.all([deleteItem(TOKEN_KEY), deleteItem(USERNAME_KEY)]);
}
