import { Platform } from 'react-native';

import {
  clearPersistedApiBaseUrl,
  loadPersistedApiBaseUrl,
  persistApiBaseUrl,
} from '@/src/api/base-url-storage';

export const DEFAULT_CLOUD_API_BASE_URL = 'https://open-locker.cloud/api';

let runtimeApiBaseUrl: string | null = null;

function removeTrailingSlash(url: string): string {
  return url.replace(/\/+$/, '');
}

function defaultApiBaseUrl(): string {
  if (!__DEV__) {
    return DEFAULT_CLOUD_API_BASE_URL;
  }

  if (Platform.OS === 'android') {
    return 'http://10.0.2.2/api';
  }

  return 'http://localhost/api';
}

export function normalizeApiBaseUrl(rawValue: string): string | null {
  let value = rawValue.trim();
  if (value.length === 0) {
    return null;
  }

  if (!/^https?:\/\//i.test(value)) {
    value = `https://${value}`;
  }

  try {
    const parsed = new URL(value);
    const hasApiPath = /\/api\/?$/i.test(parsed.pathname);
    if (!hasApiPath) {
      const normalizedPath = parsed.pathname === '/' ? '' : removeTrailingSlash(parsed.pathname);
      parsed.pathname = `${normalizedPath}/api`;
    }

    return removeTrailingSlash(parsed.toString());
  } catch {
    return null;
  }
}

/**
 * Returns the API base URL including the `/api` prefix.
 */
export function getApiBaseUrl(): string {
  if (runtimeApiBaseUrl) {
    return runtimeApiBaseUrl;
  }

  const fromEnv = process.env.EXPO_PUBLIC_API_BASE_URL;
  if (fromEnv && fromEnv.trim().length > 0) {
    return normalizeApiBaseUrl(fromEnv) ?? defaultApiBaseUrl();
  }

  return defaultApiBaseUrl();
}

export async function hydrateApiBaseUrl(): Promise<void> {
  const persisted = await loadPersistedApiBaseUrl();
  if (!persisted) {
    runtimeApiBaseUrl = null;
    return;
  }

  runtimeApiBaseUrl = normalizeApiBaseUrl(persisted);
}

export async function setApiBaseUrl(rawValue: string): Promise<string | null> {
  const normalized = normalizeApiBaseUrl(rawValue);
  if (!normalized) {
    return null;
  }

  runtimeApiBaseUrl = normalized;
  await persistApiBaseUrl(normalized);
  return normalized;
}

export async function resetApiBaseUrlToDefault(): Promise<void> {
  runtimeApiBaseUrl = null;
  await clearPersistedApiBaseUrl();
}
