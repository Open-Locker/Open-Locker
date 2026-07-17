// @ts-check
import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
import sitemap from '@astrojs/sitemap';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
const site = process.env.SITE_URL ?? 'http://localhost:4321';

export default defineConfig({
	site: new URL(site).toString(),
	base: process.env.BASE_PATH ?? '/',
	integrations: [mdx(), sitemap()],
	vite: {
		plugins: [tailwindcss()],
	},
});
