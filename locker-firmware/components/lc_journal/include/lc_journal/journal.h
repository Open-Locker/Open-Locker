#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_ports/command_journal_port.h"

/*
 * Command journal over a flash partition split into two regions. One region
 * is appended to; when it fills, the records still needed are copied into the
 * other, which then becomes active. A torn last record counts as never
 * written; any other damage marks the journal unhealthy. Record format and
 * retention: docs/adr/0068-esp32-command-journal-record-format-and-retention.md.
 */

/* Open transactions: claimed, or completed and not yet delivered. Never pruned. */
#define LC_JOURNAL_OPEN_MAX 32
/* Delivered transactions kept across a compaction, newest first. */
#define LC_JOURNAL_DELIVERED_KEEP 256
#define LC_JOURNAL_TRANSACTIONS_MAX (LC_JOURNAL_OPEN_MAX + LC_JOURNAL_DELIVERED_KEEP)
#define LC_JOURNAL_MESSAGES_MAX 512
#define LC_JOURNAL_SECTOR_SIZE 4096

/* The flash partition: two regions of whole sectors. Erased bytes read as 0xFF. */
typedef struct {
    void *ctx;
    size_t capacity;
    bool (*read)(void *ctx, size_t offset, void *out, size_t length);
    /* May write only part of `data` before failing, as a power cut would. */
    bool (*write)(void *ctx, size_t offset, const void *data, size_t length);
    /* Erases whole sectors. A cut erase leaves the sector in any state. */
    bool (*erase)(void *ctx, size_t offset, size_t length);
} lc_flash_io_t;

/* One transaction in the RAM index. Its texts stay in flash. */
typedef struct {
    uint64_t hash;
    uint32_t claim;
    /* 0 while there is no completion record. */
    uint32_t completion;
    /* Bytes of its claim, completion and delivered records in the active region. */
    uint32_t bytes;
    uint8_t id_length;
    uint8_t action_length;
    uint8_t state;
} lc_journal_transaction_t;

/* One message ID: the second text of the record at `record`. */
typedef struct {
    uint64_t hash;
    uint32_t record;
    uint16_t transaction;
} lc_journal_message_t;

/* Caller-allocated. Fields are private; the implementation may add to them. */
typedef struct {
    lc_flash_io_t io;
    bool healthy;
    size_t region_size;
    unsigned active;
    /* The active region has its header; false only for an empty journal. */
    bool started;
    uint32_t generation;
    /* Where the next record goes: the end of the last committed one. */
    size_t next_offset;
    /* The last record is torn; the next append compacts first. */
    bool torn;
    /* Something was appended since the last compaction. */
    bool appended;
    /* Sectors of the spare region erased since the start, and whether it is blank. */
    size_t spare_erased;
    bool spare_ready;
    /* The spare was checked for programmed bytes once after the start. */
    bool spare_checked;
    unsigned blank_failures;
    size_t open_count;
    size_t count;
    lc_journal_transaction_t transactions[LC_JOURNAL_TRANSACTIONS_MAX];
    size_t message_count;
    lc_journal_message_t messages[LC_JOURNAL_MESSAGES_MAX];
} lc_journal_t;

/* Reads every record of the active region. Writes nothing. False when unhealthy. */
bool lc_journal_open(lc_journal_t *journal, lc_flash_io_t io);

/*
 * One step of background work: checks the spare region, erases one sector of
 * it, checks it is blank, or compacts when the active region is more than 75 %
 * full or its last record is torn. Returns true while there is more to do.
 * Call it when the owner task is idle.
 */
bool lc_journal_maintain(lc_journal_t *journal);

lc_command_journal_port_t lc_journal_port(lc_journal_t *journal);
