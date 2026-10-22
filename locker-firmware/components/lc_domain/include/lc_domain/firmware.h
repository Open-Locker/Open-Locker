#pragma once

#include <stdbool.h>

/* Firmware updates over the air (ADR-0075, ADR-0076). */

/* The image's version field is 32 bytes with the terminator. */
#define LC_FIRMWARE_VERSION_MAX 31
#define LC_FIRMWARE_URL_MAX 256
/* How long a new image has to prove itself before the unit goes back (ADR-0075). */
#define LC_TRIAL_WINDOW_MS 180000

/* An `update_firmware` command as received. Only the shape is checked. */
typedef struct {
    char version[LC_FIRMWARE_VERSION_MAX + 1];
    char url[LC_FIRMWARE_URL_MAX + 1];
    bool allow_downgrade;
} lc_firmware_update_request_t;

typedef enum {
    LC_OTA_SLOT_0 = 0,
    LC_OTA_SLOT_1,
} lc_ota_slot_t;

/* What a device that can update itself reports in its heartbeat. */
typedef struct {
    char version[LC_FIRMWARE_VERSION_MAX + 1];
    lc_ota_slot_t slot;
    /* True until the running image has marked itself valid. */
    bool trial;
    /* Empty when the device has not rolled back. */
    char last_rollback_version[LC_FIRMWARE_VERSION_MAX + 1];
    /* The update adapter is wired, so the backend may send update_firmware. */
    bool update_capable;
} lc_firmware_status_t;
