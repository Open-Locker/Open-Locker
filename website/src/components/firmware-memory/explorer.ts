import { flashTree } from './data';
import { ramTree } from './ram';
import { formatBytes, imageFill, layout, regions, type MemoryNode } from './model';

function findPath(node: MemoryNode, id: string, ancestors: MemoryNode[] = []): MemoryNode[] | undefined {
	const path = [...ancestors, node];
	if (node.id === id) return path;
	for (const child of node.children ?? []) {
		const found = findPath(child, id, path);
		if (found) return found;
	}
}

function button(label: string, id: string) {
	const element = document.createElement('button');
	element.type = 'button';
	element.textContent = label;
	element.dataset.select = id;
	return element;
}

class FirmwareMemory extends HTMLElement {
	private tree = flashTree();
	private view = this.tree;
	private selection = this.tree;

	connectedCallback() {
		this.tree = this.dataset.memory === 'ram' ? ramTree() : flashTree();
		this.view = this.selection = this.tree;
		this.addEventListener('click', (event) => {
			const target = (event.target as HTMLElement).closest<HTMLButtonElement>('button[data-select]');
			if (!target) return;
			this.select(target.dataset.select!);
			if (!target.isConnected) this.querySelector<HTMLButtonElement>('[data-breadcrumb] button:last-of-type')?.focus();
		});
		this.querySelector<HTMLSelectElement>('[data-region]')!.addEventListener('change', (event) => {
			const id = (event.target as HTMLSelectElement).value;
			if (id) this.select(id);
		});
		this.querySelector<HTMLSelectElement>('[data-scenario]')?.addEventListener('change', (event) => {
			this.tree = flashTree((event.target as HTMLSelectElement).value === 'ota');
			this.view = findPath(this.tree, this.view.id)?.at(-1) ?? this.tree;
			this.selection = findPath(this.tree, this.selection.id)?.at(-1) ?? this.view;
			this.render();
		});
	}

	private select(id: string) {
		const path = findPath(this.tree, id);
		if (!path) return;
		this.selection = path.at(-1)!;
		if (this.selection.children) this.view = this.selection;
		this.render();
	}

	private render() {
		const path = findPath(this.tree, this.view.id)!;
		this.querySelector('[data-breadcrumb]')!.replaceChildren(...path.map((node) => {
			const item = button(node.id === 'flash' ? 'Flash' : node.label, node.id);
			if (node.id === this.view.id) item.setAttribute('aria-current', 'location');
			return item;
		}));
		const children = regions(this.view);
		const select = this.querySelector<HTMLSelectElement>('[data-region]')!;
		select.replaceChildren(new Option('Explore region…', ''), ...children.map((node) =>
			new Option(`${node.label} · ${formatBytes(node.bytes)}`, node.id)));
		this.querySelector('[data-map]')!.replaceChildren(...layout(children).map(({ node, x, y, width, height }) => {
			const tile = button('', node.id);
			tile.className = 'memory-tile';
			tile.dataset.kind = node.id === 'journal' ? 'dynamic' : node.kind;
			tile.dataset.small = String(width < 20 || height < 14);
			tile.dataset.tiny = String(width < 5 || height < 3);
			tile.style.cssText = `left:${x}%;top:${y / 60 * 100}%;width:${width}%;height:${height / 60 * 100}%;${imageFill(node)}`;
			tile.title = `${node.label}: ${formatBytes(node.bytes)}. ${node.note}`;
			tile.setAttribute('aria-label', tile.title);
			const label = document.createElement('strong');
			label.textContent = node.label;
			const size = document.createElement('span');
			size.textContent = formatBytes(node.bytes);
			tile.append(label, size);
			return tile;
		}));
		this.querySelector('[data-detail]')!.textContent = this.selection === this.view ? ''
			: `${this.selection.label} · ${formatBytes(this.selection.bytes)}`;
	}
}

if (!customElements.get('firmware-memory')) customElements.define('firmware-memory', FirmwareMemory);
