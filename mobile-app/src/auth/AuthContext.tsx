import React from 'react';

import { login as loginApi } from '@/src/api/authApi';
import { deleteItem, getItem, setItem } from '@/src/auth/storage';

type AuthState = {
  token: string | null;
  userName: string | null;
  isLoading: boolean;
};

type AuthContextValue = AuthState & {
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
};

const TOKEN_KEY = 'open-locker.token';
const USERNAME_KEY = 'open-locker.userName';

const AuthContext = React.createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = React.useState<AuthState>({
    token: null,
    userName: null,
    isLoading: true,
  });

  React.useEffect(() => {
    let cancelled = false;
    (async () => {
      const [token, userName] = await Promise.all([getItem(TOKEN_KEY), getItem(USERNAME_KEY)]);
      if (cancelled) return;
      setState({ token, userName, isLoading: false });
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = React.useCallback(async (email: string, password: string) => {
    const res = await loginApi(email, password);
    await Promise.all([setItem(TOKEN_KEY, res.token), setItem(USERNAME_KEY, res.name)]);
    setState({ token: res.token, userName: res.name, isLoading: false });
  }, []);

  const signOut = React.useCallback(async () => {
    await Promise.all([deleteItem(TOKEN_KEY), deleteItem(USERNAME_KEY)]);
    setState({ token: null, userName: null, isLoading: false });
  }, []);

  const value: AuthContextValue = React.useMemo(
    () => ({
      ...state,
      signIn,
      signOut,
    }),
    [state, signIn, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = React.useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return ctx;
}
