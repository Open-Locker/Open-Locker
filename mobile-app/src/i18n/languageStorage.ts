import { getItem, setItem } from '@/src/auth/storage';
import type { AppLanguage } from '@/src/i18n/resources';

const APP_LANGUAGE_KEY = 'open-locker.appLanguage';

export async function loadPersistedAppLanguage(): Promise<AppLanguage | null> {
  const value = await getItem(APP_LANGUAGE_KEY);
  if (value === 'en' || value === 'de') {
    return value;
  }
  return null;
}

export async function persistAppLanguage(language: AppLanguage): Promise<void> {
  await setItem(APP_LANGUAGE_KEY, language);
}
