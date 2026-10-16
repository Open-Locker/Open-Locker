#pragma once

#include <stdbool.h>
#include <stddef.h>

#include "lc_ports/store_ports.h"

/*
 * Persistent storage on the ESP32 flash: small records in NVS (settings,
 * identity, the applied mapping), and the raw partition the command journal
 * is written to.
 *
 * Each record is one NVS blob, so a save replaces the whole record or none of
 * it. Credentials sit unencrypted until flash encryption is decided (plan,
 * section 7).
 */

/* Initialises NVS. An NVS partition that cannot be read is erased, which un-enrolls the device. */
bool lc_storage_init(void);

lc_config_store_port_t lc_storage_config_store(void);
lc_identity_store_port_t lc_storage_identity_store(void);
lc_settings_store_port_t lc_storage_settings_store(void);

/* ---- The journal partition ---- */

typedef struct {
    const void *partition;
    size_t capacity;
} lc_partition_t;

/* Finds the data partition with this label. */
bool lc_partition_open(lc_partition_t *partition, const char *label);

/* The shape lc_flash_io_t expects. `ctx` is an lc_partition_t. */
bool lc_partition_read(void *ctx, size_t offset, void *out, size_t length);
bool lc_partition_write(void *ctx, size_t offset, const void *data, size_t length);
bool lc_partition_erase(void *ctx, size_t offset, size_t length);
