// @ts-check
import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
import sitemap from '@astrojs/sitemap';
import starlight from '@astrojs/starlight';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
const site = process.env.SITE_URL ?? 'http://localhost:4321';
const legalRouteAlternates = new Map([
	['/privacy-policy/', { en: '/privacy-policy/', de: '/de/datenschutz/' }],
	['/de/datenschutz/', { en: '/privacy-policy/', de: '/de/datenschutz/' }],
	['/imprint/', { en: '/imprint/', de: '/de/impressum/' }],
	['/de/impressum/', { en: '/imprint/', de: '/de/impressum/' }],
]);

export default defineConfig({
	site: new URL(site).toString(),
	redirects: {
		'/datenschutz': '/de/datenschutz/',
		'/impressum': '/de/impressum/',
	},
	i18n: {
		defaultLocale: 'en',
		locales: ['en', 'de'],
		routing: { prefixDefaultLocale: false },
	},
	integrations: [
		starlight({
			title: 'Open Locker',
			logo: { src: './public/logo-open-locker.svg' },
			customCss: ['./src/styles/starlight-custom.css'],
			// Slugs, not folders, so existing URLs and the German mirror stay unchanged.
			sidebar: [
				'dokumentation',
				'dokumentation/vision',
				{
					label: 'Build & run a locker',
					translations: { de: 'Locker aufbauen & betreiben' },
					items: [
						'dokumentation/hardware',
						{
							label: 'ESP32 controller',
							translations: { de: 'ESP32-Controller' },
							autogenerate: { directory: 'dokumentation/esp32' },
						},
						'dokumentation/operations',
					],
				},
				{
					label: 'Develop',
					translations: { de: 'Entwickeln' },
					items: [
						'dokumentation/getting-started',
						'dokumentation/architecture',
						'dokumentation/contributing',
						'dokumentation/reference',
					],
				},
			],
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/Open-Locker/Open-Locker' },
				{ icon: 'discord', label: 'Discord', href: 'https://discord.gg/rZ74RYKN3H' },
			],
			components: {
				Header: './src/components/Header.astro',
				ThemeSelect: './src/components/EmptyThemeSelect.astro',
				LanguageSelect: './src/components/LanguageSelect.astro',
				SocialIcons: './src/components/SocialIcons.astro',
				SiteTitle: './src/components/StarlightSiteTitle.astro',
			},
		}),
		mdx(),
		sitemap({
			i18n: {
				defaultLocale: 'en',
				locales: { de: 'de', en: 'en' },
			},
			serialize(item) {
				const alternates = legalRouteAlternates.get(new URL(item.url).pathname);
				if (alternates) {
					item.links = [
						{ lang: 'en', url: new URL(alternates.en, site).href },
						{ lang: 'de', url: new URL(alternates.de, site).href },
					];
				}
				return item;
			},
		}),
	],
	vite: {
		plugins: [tailwindcss()],
	},
});
