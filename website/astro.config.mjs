// @ts-check
import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
import sitemap from '@astrojs/sitemap';
import starlight from '@astrojs/starlight';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
const site = process.env.SITE_URL ?? 'http://localhost:4321';

export default defineConfig({
	site: new URL(site).toString(),
	base: process.env.BASE_PATH ?? '/',
	i18n: {
		defaultLocale: 'de',
		locales: ['de', 'en'],
		fallback: { en: 'de' },
		routing: { prefixDefaultLocale: false },
	},
	integrations: [
		starlight({
			title: { de: 'Open Locker', en: 'Open Locker' },
			logo: { src: './public/logo-open-locker.svg' },
			sidebar: [
				{
					label: 'Dokumentation',
					translations: { en: 'Documentation' },
					autogenerate: { directory: 'dokumentation' },
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
			},
		}),
		mdx(),
		sitemap({
			i18n: {
				defaultLocale: 'de',
				locales: { de: 'de', en: 'en' },
			},
		}),
	],
	vite: {
		plugins: [tailwindcss()],
	},
});
