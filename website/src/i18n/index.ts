import { de, type Dictionary } from './de';
import { en } from './en';

export const LOCALES = ['en', 'de'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'en';

export const translations: Record<Locale, Dictionary> = { de, en };

export function useTranslations(locale: Locale): Dictionary {
	return translations[locale];
}

export function getLocaleFromUrl(url: URL): Locale {
	return url.pathname === '/de' || url.pathname.startsWith('/de/') ? 'de' : 'en';
}

/** Strip a leading /de from a pathname, returning the default-locale path. */
export function stripLocale(pathname: string): string {
	if (pathname === '/de') return '/';
	return pathname.startsWith('/de/') ? pathname.slice(3) : pathname;
}

/** Return the given path in the requested locale. */
export function localizePath(pathname: string, locale: Locale): string {
	const base = stripLocale(pathname);
	return locale === 'de' ? `/de${base === '/' ? '/' : base}` : base;
}
