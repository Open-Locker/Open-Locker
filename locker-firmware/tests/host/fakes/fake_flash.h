#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_journal/journal.h"

/*
 * A flash region in memory. Erased bytes are 0xFF and a write can only clear
 * bits, as on NOR flash, so rewriting a record shows up as corruption. A write
 * can be cut short to model a power cut.
 */

#define FAKE_FLASH_MAX (64 * 1024)

typedef struct {
    uint8_t memory[FAKE_FLASH_MAX];
    size_t capacity;
    size_t bytes_written;
    unsigned write_calls;
    bool power_cut_armed;
    unsigned reads_until_failure;
    size_t bytes_until_power_cut;
} fake_flash_t;

void fake_flash_init(fake_flash_t *flash, size_t capacity);
lc_flash_io_t fake_flash_io(fake_flash_t *flash);

/* Only `bytes` more bytes reach the flash; every write after that fails. */
void fake_flash_cut_power_after(fake_flash_t *flash, size_t bytes);
void fake_flash_restore_power(fake_flash_t *flash);
/* The `nth` read from now fails, counting from 1; 0 takes the failure back. */
void fake_flash_fail_read(fake_flash_t *flash, unsigned nth);
