// @ts-check
import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
import sitemap from '@astrojs/sitemap';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
const site = process.env.SITE_URL ?? 'http://localhost:4321';

export default defineConfig({
	site: new URL(site).toString(),
	i18n: {
		defaultLocale: 'de',
		locales: ['de', 'en'],
		fallback: { en: 'de' },
		routing: { prefixDefaultLocale: false },
	},
	integrations: [
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
