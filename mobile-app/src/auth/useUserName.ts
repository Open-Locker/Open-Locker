import { useGetUserQuery } from '@/src/store/generatedApi';
import { useAppSelector } from '@/src/store/hooks';
import { formatUserName } from '@/src/utils/userName';

/**
 * The signed-in user's display name.
 *
 * Read from the API rather than from the auth slice. The slice is written once,
 * at login, and nothing rewrites it afterwards — so a name changed in the account
 * screen stayed stale everywhere it was displayed, and survived a restart because
 * the slice is persisted. Only logging out and back in cleared it.
 *
 * The slice is still the fallback, and only that: on a cold start it has a name
 * to show immediately, where the query has not resolved yet. Trading a stale name
 * for a blank one would not be an improvement.
 */
export function useUserName(): string | null {
  const persistedName = useAppSelector((state) => state.auth.userName);
  const { data: user } = useGetUserQuery();

  if (user) {
    return formatUserName(user.first_name, user.last_name) || null;
  }

  return persistedName;
}
