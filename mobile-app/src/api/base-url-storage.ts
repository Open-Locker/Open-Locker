import { deleteItem, getItem, setItem } from '@/src/auth/storage';

const API_BASE_URL_KEY = 'open-locker.apiBaseUrl';

export async function loadPersistedApiBaseUrl(): Promise<string | null> {
  return getItem(API_BASE_URL_KEY);
}

export async function persistApiBaseUrl(value: string): Promise<void> {
  await setItem(API_BASE_URL_KEY, value);
}

export async function clearPersistedApiBaseUrl(): Promise<void> {
  await deleteItem(API_BASE_URL_KEY);
}
