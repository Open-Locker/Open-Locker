#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_ports/command_journal_port.h"

/*
 * Append-only command journal over a region of flash. Records are only ever
 * appended; nothing is rewritten or erased. A record that fails its check, or
 * makes no sense, marks the journal unhealthy. A write that was cut short
 * before its commit marker is skipped. Record format and retention:
 * docs/adr/0068-esp32-command-journal-record-format-and-retention.md.
 */

#define LC_JOURNAL_TRANSACTIONS_MAX 64
#define LC_JOURNAL_MESSAGES_MAX 128

/* The flash region. Erased bytes read as 0xFF. */
typedef struct {
    void *ctx;
    size_t capacity;
    bool (*read)(void *ctx, size_t offset, void *out, size_t length);
    /* May write only part of `data` before failing, as a power cut would. */
    bool (*write)(void *ctx, size_t offset, const void *data, size_t length);
} lc_flash_io_t;

typedef struct {
    char id[LC_ID_MAX + 1];
    uint8_t owner;
} lc_journal_message_t;

/* Caller-allocated. Fields are private; the implementation may add to them. */
typedef struct {
    lc_flash_io_t io;
    bool healthy;
    size_t next_offset;
    /* A write was cut short at `torn_start`; the next append makes its debris unreadable. */
    bool torn;
    size_t torn_start;
    size_t count;
    lc_journal_entry_t entries[LC_JOURNAL_TRANSACTIONS_MAX];
    size_t message_count;
    lc_journal_message_t messages[LC_JOURNAL_MESSAGES_MAX];
} lc_journal_t;

/* Reads every record. Writes nothing. False when the journal is unhealthy. */
bool lc_journal_open(lc_journal_t *journal, lc_flash_io_t io);

lc_command_journal_port_t lc_journal_port(lc_journal_t *journal);
