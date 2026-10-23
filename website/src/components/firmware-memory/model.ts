export type MemoryNode = {
	id: string;
	label: string;
	bytes: number;
	kind: 'group' | 'image' | 'headroom' | 'dynamic' | 'payload' | 'reserved';
	note: string;
	offset?: number;
	children?: MemoryNode[];
};

export const formatBytes = (bytes: number) =>
	`${bytes.toLocaleString('en-US')} B · ${(bytes / 1024).toLocaleString('en-US', { maximumFractionDigits: 2 })} KiB`;

export type Tile = { node: MemoryNode; x: number; y: number; width: number; height: number };

export const regions = (node: MemoryNode): MemoryNode[] => node.id === 'flash'
	? node.children!.flatMap((child) => child.id === 'firmware' ? child.children! : [child])
	: node.children ?? [];

export function imageFill(node: MemoryNode): string {
	if (node.children?.every((child) => child.kind === 'headroom')) return 'background:var(--tile-headroom)';
	if (!node.children?.some((child) => child.kind === 'image')) return '';
	const share = node.children.filter((child) => child.kind === 'image').reduce((sum, child) => sum + child.bytes, 0) / node.bytes * 100;
	return `background:linear-gradient(90deg,var(--tile-image) ${share}%,var(--tile-headroom) ${share}%)`;
}

// Split near half the bytes, then divide the longer side. Areas remain proportional.
export function layout(nodes: MemoryNode[], x = 0, y = 0, width = 100, height = 60): Tile[] {
	if (!nodes.length) return [];
	if (nodes.length === 1) return [{ node: nodes[0], x, y, width, height }];
	const total = nodes.reduce((sum, node) => sum + node.bytes, 0);
	let split = 1;
	let left = nodes[0].bytes;
	while (split < nodes.length - 1 && Math.abs(left + nodes[split].bytes - total / 2) < Math.abs(left - total / 2)) {
		left += nodes[split++].bytes;
	}
	const fraction = left / total;
	return width >= height
		? [...layout(nodes.slice(0, split), x, y, width * fraction, height),
			...layout(nodes.slice(split), x + width * fraction, y, width * (1 - fraction), height)]
		: [...layout(nodes.slice(0, split), x, y, width, height * fraction),
			...layout(nodes.slice(split), x, y + height * fraction, width, height * (1 - fraction))];
}
