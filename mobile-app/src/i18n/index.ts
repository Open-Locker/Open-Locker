import { createInstance } from 'i18next';
import { getLocales } from 'expo-localization';
import { initReactI18next } from 'react-i18next';

import { loadPersistedAppLanguage, persistAppLanguage } from '@/src/i18n/languageStorage';
import { resources, type AppLanguage } from '@/src/i18n/resources';

const fallbackLanguage: AppLanguage = 'en';
const supportedLanguages = Object.keys(resources) as AppLanguage[];
const i18next = createInstance();

function resolveDeviceLanguage(): AppLanguage {
  const locale = getLocales()[0];
  const candidates = [locale?.languageCode, locale?.languageTag?.split('-')[0]]
    .filter((value): value is string => Boolean(value))
    .map((value) => value.toLowerCase());

  for (const candidate of candidates) {
    if (supportedLanguages.includes(candidate as AppLanguage)) {
      return candidate as AppLanguage;
    }
  }

  return fallbackLanguage;
}

function normalizeLanguage(language: string | undefined | null): AppLanguage {
  const candidate = language?.split('-')[0]?.toLowerCase();
  return candidate === 'de' ? 'de' : 'en';
}

if (!i18next.isInitialized) {
  i18next.use(initReactI18next).init({
    resources,
    lng: resolveDeviceLanguage(),
    fallbackLng: fallbackLanguage,
    interpolation: {
      escapeValue: false,
    },
    react: {
      useSuspense: false,
    },
  });
}

export function getCurrentAppLanguage(): AppLanguage {
  return normalizeLanguage(i18next.resolvedLanguage ?? i18next.language);
}

export async function hydrateAppLanguage(): Promise<void> {
  const persistedLanguage = await loadPersistedAppLanguage();
  if (!persistedLanguage) {
    return;
  }

  if (getCurrentAppLanguage() !== persistedLanguage) {
    await i18next.changeLanguage(persistedLanguage);
  }
}

export async function setAppLanguage(language: AppLanguage): Promise<void> {
  await i18next.changeLanguage(language);
  await persistAppLanguage(language);
}

export default i18next;
