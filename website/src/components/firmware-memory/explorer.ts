import { flashTree } from './data';
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
		this.addEventListener('click', (event) => {
			const target = (event.target as HTMLElement).closest<HTMLButtonElement>('button[data-select]');
			if (!target) return;
			const path = findPath(this.tree, target.dataset.select!);
			if (!path) return;
			this.selection = path.at(-1)!;
			if (this.selection.children) this.view = this.selection;
			this.render();
			// The map/list was replaced; keep keyboard focus on a stable navigation control.
			if (!target.isConnected) this.querySelector<HTMLButtonElement>('[data-breadcrumb] button:last-of-type')?.focus();
		});
		this.querySelector<HTMLSelectElement>('[data-scenario]')!.addEventListener('change', (event) => {
			this.tree = flashTree((event.target as HTMLSelectElement).value === 'ota');
			this.view = findPath(this.tree, this.view.id)?.at(-1) ?? this.tree;
			this.selection = findPath(this.tree, this.selection.id)?.at(-1) ?? this.view;
			this.render();
		});
	}

	private render() {
		const path = findPath(this.tree, this.view.id)!;
		this.querySelector('[data-breadcrumb]')!.replaceChildren(...path.map((node) => {
			const item = button(node.label, node.id);
			if (node.id === this.view.id) item.setAttribute('aria-current', 'location');
			return item;
		}));
		this.querySelectorAll<HTMLButtonElement>('.memory-scopes button').forEach((item) => {
			item.setAttribute('aria-pressed', String(path.some((node) => node.id === item.dataset.select) &&
				(item.dataset.select !== 'flash' || this.view.id === 'flash')));
		});
		const children = regions(this.view);
		this.querySelector('[data-map]')!.replaceChildren(...layout(children).map(({ node, x, y, width, height }) => {
			const tile = button('', node.id);
			tile.className = 'memory-tile';
			tile.dataset.kind = node.id === 'journal' ? 'dynamic' : node.kind;
			tile.dataset.small = String(width < 20 || height < 14);
			tile.dataset.tiny = String(width < 5 || height < 3);
			tile.style.cssText = `left:${x}%;top:${y / 60 * 100}%;width:${width}%;height:${height / 60 * 100}%;${imageFill(node)}`;
			tile.title = `${node.label}: ${formatBytes(node.bytes)}`;
			tile.setAttribute('aria-label', `${tile.title}. ${node.children ? 'Explore region.' : node.note}`);
			const label = document.createElement('strong');
			label.textContent = node.label;
			const size = document.createElement('span');
			size.textContent = `${(node.bytes / 1024).toLocaleString('en-US', { maximumFractionDigits: 2 })} KiB`;
			const action = document.createElement('small');
			action.textContent = node.children ? 'Explore →' : 'Details';
			tile.append(label, size, action);
			return tile;
		}));
		const heading = document.createElement('strong');
		heading.textContent = `${this.selection.label} · ${formatBytes(this.selection.bytes)}`;
		const note = document.createElement('p');
		note.textContent = this.selection.note;
		if (this.selection.offset !== undefined) note.textContent += ` Starts at 0x${this.selection.offset.toString(16).toUpperCase()}.`;
		this.querySelector('[data-detail]')!.replaceChildren(heading, note);
		this.querySelector('[data-caption]')!.textContent = `Regions in ${this.view.label}`;
		this.querySelector('[data-rows]')!.replaceChildren(...children.map((node) => {
			const row = document.createElement('tr');
			const label = document.createElement('th');
			label.scope = 'row';
			label.append(button(node.label + (node.children ? ' →' : ''), node.id));
			const bytes = document.createElement('td');
			bytes.textContent = formatBytes(node.bytes);
			const share = document.createElement('td');
			share.textContent = `${(node.bytes / this.view.bytes * 100).toFixed(2)}%`;
			row.append(label, bytes, share);
			return row;
		}));
	}
}

if (!customElements.get('firmware-memory')) customElements.define('firmware-memory', FirmwareMemory);
