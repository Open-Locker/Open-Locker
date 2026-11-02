// Checks WCAG AA colour contrast on every built page, with the visitor's system set to
// light and to dark. Run `pnpm build` first, then `pnpm test:contrast [path-filter]`.
import { readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { extname, join, relative } from 'node:path';
import { chromium } from 'playwright-core';

const DIST = new URL('../dist/', import.meta.url).pathname;
const TYPES = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.mjs': 'text/javascript', '.svg': 'image/svg+xml', '.json': 'application/json', '.png': 'image/png', '.webp': 'image/webp' };
const axeSource = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');

const htmlFiles = (dir) =>
	readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name);
		return entry.isDirectory() ? htmlFiles(path) : entry.name === 'index.html' ? [path] : [];
	});

const server = createServer((request, response) => {
	const path = decodeURIComponent(new URL(request.url, 'http://x').pathname);
	for (const file of [join(DIST, path), join(DIST, path, 'index.html')]) {
		try {
			const body = readFileSync(file);
			response.writeHead(200, { 'content-type': TYPES[extname(file)] ?? 'application/octet-stream' });
			return response.end(body);
		} catch {}
	}
	response.writeHead(404).end();
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;

const filter = process.argv[2] ?? '';
const paths = htmlFiles(DIST)
	.map((file) => `/${relative(DIST, file).replace(/index\.html$/, '')}`)
	.filter((path) => path.includes(filter))
	.sort();

// Uses the installed Chrome; set CHROME_PATH to use another browser.
const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' });
const findings = new Map();
const unchecked = new Map();

for (const colorScheme of ['light', 'dark']) {
	const page = await browser.newContext({ colorScheme, viewport: { width: 1280, height: 900 } }).then((context) => context.newPage());
	for (const path of paths) {
		await page.goto(origin + path, { waitUntil: 'load' });
		await page.waitForTimeout(page.url().includes('esp32') ? 700 : 50); // Mermaid and the treemaps render after load.
		await page.evaluate(axeSource);
		const { violations, incomplete } = await page.evaluate(() =>
			window.axe.run(document, { runOnly: ['color-contrast'], resultTypes: ['violations', 'incomplete'] }),
		);
		for (const node of incomplete.flatMap((rule) => rule.nodes)) {
			const reason = node.any[0]?.data?.messageKey ?? 'other';
			const entry = unchecked.get(reason) ?? { count: 0, pages: new Set() };
			entry.count += 1;
			entry.pages.add(path);
			unchecked.set(reason, entry);
		}
		for (const node of violations.flatMap((rule) => rule.nodes)) {
			const data = node.any[0]?.data ?? {};
			const key = `${data.fgColor} on ${data.bgColor}`;
			const finding = findings.get(key) ?? { ratio: data.contrastRatio, needed: data.expectedContrastRatio, count: 0, examples: new Map() };
			finding.count += 1;
			if (finding.examples.size < 4) finding.examples.set(`${path} (${colorScheme})`, node.target.join(' '));
			findings.set(key, finding);
		}
	}
}
await browser.close();
server.close();

for (const [colors, finding] of [...findings].sort((a, b) => b[1].count - a[1].count)) {
	console.log(`\n${colors}: ${finding.ratio}, needs ${finding.needed}, ${finding.count} elements`);
	for (const [where, selector] of finding.examples) console.log(`  ${where}: ${selector.slice(0, 110)}`);
}
console.log(`\n${paths.length} pages in 2 colour schemes: ${[...findings.values()].reduce((sum, finding) => sum + finding.count, 0)} failing elements, ${[...unchecked.values()].reduce((sum, entry) => sum + entry.count, 0)} that axe could not judge:`);
for (const [reason, entry] of unchecked) console.log(`  ${reason}: ${entry.count}, on ${[...entry.pages].join(' ')}`);
process.exit(findings.size > 0 ? 1 : 0);
