import { de, type Dictionary } from './de';
import { en } from './en';

export const LOCALES = ['de', 'en'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'de';

export const translations: Record<Locale, Dictionary> = { de, en };

export function useTranslations(locale: Locale): Dictionary {
	return translations[locale];
}

export function getLocaleFromUrl(url: URL): Locale {
	return url.pathname === '/en' || url.pathname.startsWith('/en/') ? 'en' : 'de';
}

/** Strip a leading /en from a pathname, returning the default-locale path. */
export function stripLocale(pathname: string): string {
	if (pathname === '/en') return '/';
	return pathname.startsWith('/en/') ? pathname.slice(3) : pathname;
}

/** Return the given path in the requested locale. */
export function localizePath(pathname: string, locale: Locale): string {
	const base = stripLocale(pathname);
	return locale === 'en' ? `/en${base === '/' ? '/' : base}` : base;
}
