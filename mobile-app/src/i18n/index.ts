import { createInstance } from 'i18next';
import { getLocales } from 'expo-localization';
import { initReactI18next } from 'react-i18next';

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

export default i18next;
