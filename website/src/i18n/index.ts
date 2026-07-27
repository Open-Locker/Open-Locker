import { de, type Dictionary } from './de';
import { en } from './en';

export const LOCALES = ['en', 'de'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'en';

export const translations: Record<Locale, Dictionary> = { de, en };

const localizedLegalPaths: Record<string, Record<Locale, string>> = {
	'/privacy-policy/': { en: '/privacy-policy/', de: '/de/datenschutz/' },
	'/datenschutz/': { en: '/privacy-policy/', de: '/de/datenschutz/' },
	'/imprint/': { en: '/imprint/', de: '/de/impressum/' },
	'/impressum/': { en: '/imprint/', de: '/de/impressum/' },
};

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
	const localizedLegalPath = localizedLegalPaths[base];
	if (localizedLegalPath) return localizedLegalPath[locale];

	return locale === 'de' ? `/de${base === '/' ? '/' : base}` : base;
}
