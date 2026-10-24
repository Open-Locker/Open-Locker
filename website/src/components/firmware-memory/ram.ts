import type { MemoryNode } from './model';

// Production build 25c7e82b, ESP-IDF 6.1: esp_idf_size --format json2 and nm -S.
// Physical ranges: ESP-IDF components/heap/port/esp32/memory_layout.c.
// Runtime entries are configured budgets, not a live heap sample. RTC is extra.
const item = (id: string, label: string, bytes: number, kind: MemoryNode['kind'], note: string): MemoryNode =>
	({ id, label, bytes, kind, note });
const group = (id: string, label: string, note: string, children: MemoryNode[]): MemoryNode =>
	({ id, label, note, kind: 'group', bytes: children.reduce((sum, child) => sum + child.bytes, 0), children });

export function ramTree(): MemoryNode {
	const statics = group('ram-statics', 'Static data', 'ELF: DRAM .bss + .data, including ESP-IDF globals.', [
		item('ram-journal', 'Journal index', 15184, 'payload', 'ELF symbol: journal.'),
		item('ram-app', 'Application state', 14392, 'payload', 'ELF symbol: app; includes 64 compartment trackers.'),
		item('ram-assembler', 'MQTT receive', 8208, 'payload', 'ELF symbol: assembler.'),
		item('ram-encoders', 'JSON output buffers', 9216, 'payload', 'Two 4,608-byte encoded buffers linked in this production build.'),
		item('ram-other-statics', 'Other globals', 42847, 'payload', 'Remaining ELF DRAM static data.'),
	]);
	const runtime = group('ram-runtime', 'Known runtime budgets', 'Normal MQTT mode; excludes allocator overhead, task control blocks and variable library allocations.', [
		item('ram-queue', 'Owner queue', 22100, 'payload', '32 × 688-byte events + 84-byte queue control block.'),
		item('ram-owner-stack', 'Owner stack', 12288, 'payload', 'Allocated stack capacity; high-water usage unmeasured.'),
		item('ram-mqtt-stack', 'MQTT stack', 8192, 'payload', 'Configured stack capacity.'),
		item('ram-console-stack', 'Console stack', 8192, 'payload', 'Console task runs even with bench commands disabled.'),
		item('ram-button-stack', 'Button stack', 3072, 'payload', 'Configured reset-button stack capacity.'),
		item('ram-mqtt-buffers', 'MQTT I/O buffers', 4096, 'payload', '2 KiB input + 2 KiB output; excludes outbox and TLS.'),
	]);
	statics.kind = runtime.kind = 'payload';
	return group('ram', 'RAM', '520 KiB SRAM + 16 KiB RTC. Allocation model; live heap unmeasured.', [
		group('ram-dram', 'Data RAM', '328 KiB physical data SRAM, including ROM reservations and runtime-only heap.', [
			statics, runtime,
			item('ram-unmeasured', 'Other / unmeasured', 328 * 1024 - statics.bytes - runtime.bytes, 'dynamic', 'Wi-Fi, TLS, outbox, drivers, OS/ROM, overhead and remaining space. Not free heap.'),
		]),
		group('ram-iram', 'Instruction RAM', '192 KiB instruction SRAM, including the flash caches.', [
			item('ram-code', 'RAM code + vectors', 91239, 'image', 'ELF: 90,211 bytes .text + 1,028 bytes .vectors.'),
			item('ram-cache', 'Flash caches', 65536, 'reserved', 'Two 32 KiB cache regions; excluded from linker IRAM.'),
			item('ram-iram-other', 'Unallocated at link', 39833, 'dynamic', 'Linker remainder; runtime usage unmeasured.'),
		]),
		group('ram-rtc', 'RTC RAM', '16 KiB additional RTC memory; aliases counted only once.', [
			item('ram-rtc-static', 'RTC static data', 64, 'payload', 'ELF: 40 bytes retained + 24 bytes reserved state.'),
			item('ram-rtc-other', 'RTC remainder', 16320, 'dynamic', 'RTC fast + remaining RTC slow; usage beyond linked data unmeasured.'),
		]),
	]);
}
