import type { MemoryNode } from './model';

// Snapshot of 25c7e82b, ESP-IDF 6.1, sdkconfig.defaults + sdkconfig.production.
// Refresh with the procedure on the memory documentation page; never infer NVS/journal fullness.
export const snapshot = {
	commit: '25c7e82b',
	date: '2026-10-23',
	imageBytes: 1179648,
	imageSha256: '11f876dc15fa2eea6f2b32c6e048610136c96cb14bc45cee7fc2b9964ac849f0',
	bootloaderBytes: 26288,
	partitionTableBytes: 3072,
};
export const signedImageBytes = () => Math.ceil(snapshot.imageBytes / 4096) * 4096 + 4096;

const node = (id: string, label: string, bytes: number, kind: MemoryNode['kind'], note: string, offset?: number): MemoryNode =>
	({ id, label, bytes, kind, note, offset });
const group = (id: string, label: string, note: string, children: MemoryNode[], offset?: number): MemoryNode =>
	({ id, label, kind: 'group', bytes: children.reduce((sum, child) => sum + child.bytes, 0), note, children, offset });

function slot(id: string, label: string, offset: number, populated: boolean): MemoryNode {
	const capacity = 0x1d0000;
	return group(id, label,
		populated ? 'Representative signed production-config image. Unused bytes belong to this slot; other partitions cannot borrow them.'
			: 'Erased after a clean flash into ota_0. Reserved for the next OTA image, not general-purpose free storage.',
		populated ? [
			node(`${id}-image`, 'Firmware binary', snapshot.imageBytes, 'image', 'Measured unsigned .bin length, including image padding and checksum.'),
			node(`${id}-signature`, 'Signing overhead', signedImageBytes() - snapshot.imageBytes, 'image', 'Calculated Secure Boot v2 sector padding plus one 4,096-byte signature sector; no private key is needed to calculate its size.'),
			node(`${id}-headroom`, 'Slot headroom', capacity - signedImageBytes(), 'headroom', 'Remaining capacity after signing. Available only for growth of the firmware image.'),
		] : [node(`${id}-empty`, 'Empty OTA slot', capacity, 'headroom', 'Assumes a full-chip erase followed by a fresh flash into ota_0.')], offset);
}

export function flashTree(afterOta = false): MemoryNode {
	return group('flash', '4 MiB flash', 'Areas represent reserved capacity, not RAM. Select a tile to inspect it. Usage is exact only where backed by a binary or a fixed-size record.', [
		group('firmware', 'Firmware slots', afterOta
			? 'Illustrative state after an OTA update: both slots contain images of the measured size. A real previous version may differ.'
			: 'Clean-flash scenario: ota_0 contains the representative signed image; ota_1 is erased.', [
			slot('ota-0', 'ota_0', 0x20000, true), slot('ota-1', 'ota_1', 0x1f0000, afterOta),
		]),
		group('journal', 'Command journal', '256 KiB reserved. Actual occupancy depends on command history. These are roles, not measured used/free bytes. The regions exchange roles during compaction.', [
			node('journal-active', 'Active region', 0x20000, 'dynamic', 'Append-only command records; a 16-byte header and variable-length records up to 331 bytes. Occupancy is not measured.'),
			node('journal-spare', 'Spare region', 0x20000, 'dynamic', 'Compaction destination. May be erased, awaiting erase, or temporarily hold another valid generation. Not additional command capacity.'),
		], 0x3c0000),
		group('system', 'System & settings', 'Exact address ranges from partitions.csv, including boot space and alignment gaps.', [
			node('alignment', 'Alignment gap', 0x9000, 'reserved', '0x17000–0x20000: unassigned alignment space, not a storage partition.', 0x17000),
			group('bootloader', 'Bootloader space', '44 KiB address allowance before the partition table. Binary length is measured; the remaining space is reserved for bootloader growth.', [
				node('bootloader-image', 'Bootloader binary', snapshot.bootloaderBytes, 'image', 'Measured build/bootloader/bootloader.bin length.'),
				node('bootloader-headroom', 'Bootloader headroom', 0xb000 - snapshot.bootloaderBytes, 'headroom', 'Unused allowance before the partition table; not available to application partitions.'),
			], 0x1000),
			group('nvs', 'NVS settings', '24 KiB partition. Only the three application blob sizes below are known. The remainder includes NVS metadata, setup strings, Wi-Fi data, old entries and unused space; it is NOT all free.', [
				node('nvs-identity', 'Identity blob', 728, 'payload', '724-byte lc_identity_t + 4-byte record version. Includes fixed buffers for bootstrap and issued credentials.'),
				node('nvs-config', 'Configuration blob', 344, 'payload', '340-byte lc_config_t + 4-byte version; storage for all 64 compartment mappings.'),
				node('nvs-network', 'Network blob', 296, 'payload', '292-byte lc_network_settings_t + 4-byte version. Assumes a commissioned device with all three records present.'),
				node('nvs-other', 'Overhead / other / unmeasured', 0x6000 - 1368, 'dynamic', 'The unaccounted-for part of the partition, not a measured free-space value. Blob sizes exclude NVS entry overhead.'),
			], 0xd000),
			node('otadata', 'OTA metadata', 0x2000, 'dynamic', 'Two flash sectors for redundant boot-selection records. Reserved capacity, not the size of the live records.', 0x13000),
			node('phy', 'PHY initialization', 0x1000, 'reserved', 'Reserved radio-initialization partition. This snapshot does not measure its programmed bytes.', 0x15000),
			node('nvs-keys', 'NVS keys', 0x1000, 'reserved', 'Reserved for NVS encryption. Encryption is not enabled in this production configuration.', 0x16000),
			group('partition-table', 'Partition table', 'One 4 KiB sector reserved for the partition table.', [
				node('partition-table-image', 'Partition table binary', snapshot.partitionTableBytes, 'image', 'Measured partition-table.bin file length, including padding.'),
				node('partition-table-headroom', 'Sector remainder', 0x1000 - snapshot.partitionTableBytes, 'headroom', 'Reserved sector remainder, not application storage.'),
			], 0xc000),
			node('prefix', 'Flash prefix', 0x1000, 'reserved', '0x0000–0x1000 precedes the ESP32 bootloader. No application partition.', 0),
		]),
	]);
}
