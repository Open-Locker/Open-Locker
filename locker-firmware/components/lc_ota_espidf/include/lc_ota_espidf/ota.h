#pragma once

#include <stdbool.h>

#include "lc_domain/errors.h"
#include "lc_ports/firmware_update_port.h"

/*
 * Over-the-air updates on esp_https_ota and esp_ota_ops (ADR-0075), behind the
 * firmware update port.
 *
 * The image is downloaded by its own task, never by the owner task: the port's
 * calls only start it, ask it to stop and read the state of the image slots.
 * The download task reports how it ended through the handlers, which post to
 * the owner queue. The URL carries a download token in its query string: it is
 * handed to esp_https_ota and never logged or kept.
 *
 * esp_https_ota_finish() checks the image (and its signature, with
 * CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT) and then selects the new slot
 * itself. Nothing in this adapter selects a slot before that, and
 * select_next_slot() only says whether a verified image is staged.
 *
 * The version this unit rolled back from is not stored here: the bootloader marks
 * a slot invalid or aborted on any reset during a trial (a crash, a watchdog, a
 * power cut as well as our rollback), and last_rollback_version() reads the
 * version of that slot. A later download into the slot erases the record.
 */

typedef struct {
    void *ctx;
    /* From the download task: the new image is verified and will boot next. */
    void (*on_verified)(void *ctx);
    /* From the download task: UPDATE_DOWNLOAD_FAILED or UPDATE_VERIFY_FAILED. */
    void (*on_failed)(void *ctx, lc_error_t error);
} lc_ota_config_t;

/* The port for the application. The handlers are copied. */
lc_firmware_update_port_t lc_ota_port(const lc_ota_config_t *config);

/*
 * Runs `body(arg)` on a task of its own and deletes the task when it returns.
 * False when the task could not be created. Defined by task.c on the chip;
 * the host tests define their own.
 */
bool lc_ota_spawn(void (*body)(void *arg), void *arg);
