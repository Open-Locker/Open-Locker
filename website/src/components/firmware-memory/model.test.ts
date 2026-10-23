import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { flashTree, signedImageBytes, snapshot } from './data.ts';
import { imageFill, layout, regions, type MemoryNode } from './model.ts';

const flatten = (node: MemoryNode): MemoryNode[] => [node, ...(node.children ?? []).flatMap(flatten)];

test('both scenarios account for every flash byte and match the real partition table', () => {
	const csv = readFileSync(new URL('../../../../locker-firmware/partitions.csv', import.meta.url), 'utf8');
	for (const scenario of [false, true]) {
		const tree = flashTree(scenario);
		const nodes = flatten(tree);
		assert.equal(tree.bytes, 4 * 1024 * 1024);
		assert.equal(new Set(nodes.map((node) => node.id)).size, nodes.length);
		for (const node of nodes) {
			assert.ok(Number.isInteger(node.bytes) && node.bytes > 0, node.id);
			if (node.children) assert.equal(node.bytes, node.children.reduce((sum, child) => sum + child.bytes, 0));
		}
		for (const line of csv.split('\n').filter((line) => line.trim() && !line.startsWith('#'))) {
			const [name, , , offset, size] = line.split(',').map((part) => part.trim());
			const ids: Record<string, string> = { ota_0: 'ota-0', ota_1: 'ota-1', nvs_keys: 'nvs-keys', phy_init: 'phy' };
			const node = nodes.find((node) => node.id === (ids[name] ?? name))!;
			assert.ok(node, name);
			assert.equal(node.bytes, Number(size), name);
			assert.equal(node.offset, Number(offset), name);
		}
	}
	assert.ok(snapshot.imageBytes > 0 && snapshot.bootloaderBytes > 0);
	assert.ok(signedImageBytes() <= 0x1d0000);
	assert.equal(signedImageBytes() % 4096, 0);
});

test('treemap has proportional, bounded, non-overlapping tiles at every zoom', () => {
	for (const node of flatten(flashTree(true)).filter((node) => node.children)) {
		const tiles = layout(regions(node));
		assert.ok(Math.abs(tiles.reduce((sum, tile) => sum + tile.width * tile.height, 0) - 6000) < 1e-7);
		for (const tile of tiles) {
			assert.ok(tile.x >= 0 && tile.y >= 0 && tile.x + tile.width <= 100.00001 && tile.y + tile.height <= 60.00001);
			assert.ok(Math.abs(tile.width * tile.height / 6000 - tile.node.bytes / node.bytes) < 1e-9);
			for (const other of tiles) {
				if (tile === other) continue;
				const overlapX = Math.min(tile.x + tile.width, other.x + other.width) - Math.max(tile.x, other.x);
				const overlapY = Math.min(tile.y + tile.height, other.y + other.height) - Math.max(tile.y, other.y);
				assert.ok(overlapX < 1e-8 || overlapY < 1e-8);
			}
		}
	}
	assert.deepEqual(layout([]), []);
});

test('overview exposes both OTA partitions and signing is included in the image fill', () => {
	const tree = flashTree();
	assert.deepEqual(regions(tree).map((node) => node.id), ['ota-0', 'ota-1', 'journal', 'system']);
	const slot = regions(tree)[0];
	assert.ok(imageFill(slot).includes(String(signedImageBytes() / slot.bytes * 100)));
	assert.equal(imageFill(regions(tree)[2]), '');
	assert.equal(imageFill(regions(tree)[1]), 'background:var(--tile-headroom)');
});
