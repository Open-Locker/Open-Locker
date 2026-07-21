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
		defaultLocale: 'en',
		locales: ['en', 'de'],
		fallback: { de: 'en' },
		routing: { prefixDefaultLocale: false },
	},
	integrations: [
		starlight({
			title: { de: 'Open Locker', en: 'Open Locker' },
			logo: { src: './public/logo-open-locker.svg' },
			customCss: ['./src/styles/starlight-custom.css'],
			sidebar: [
				{
					label: 'Documentation',
					translations: { de: 'Dokumentation' },
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
				SiteTitle: './src/components/StarlightSiteTitle.astro',
			},
		}),
		mdx(),
		sitemap({
			i18n: {
				defaultLocale: 'en',
				locales: { de: 'de', en: 'en' },
			},
		}),
	],
	vite: {
		plugins: [tailwindcss()],
	},
});
