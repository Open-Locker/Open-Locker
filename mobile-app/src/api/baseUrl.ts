import { Platform } from 'react-native';

function ensureNoTrailingSlash(url: string): string {
  return url.endsWith('/') ? url.slice(0, -1) : url;
}

/**
 * Returns the API base URL including the `/api` prefix.
 *
 * Defaults:
 * - iOS simulator: http://localhost/api
 * - Android emulator: http://10.0.2.2/api
 * - Web: http://localhost/api
 */
export function getApiBaseUrl(): string {
  const fromEnv = process.env.EXPO_PUBLIC_API_BASE_URL;
  if (fromEnv && fromEnv.trim().length > 0) {
    return ensureNoTrailingSlash(fromEnv.trim());
  }

  if (Platform.OS === 'android') {
    return 'http://10.0.2.2/api';
  }

  return 'http://localhost/api';
}
