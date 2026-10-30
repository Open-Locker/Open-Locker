#pragma once

#include <stdbool.h>

#include "lc_ports/store_ports.h"

/*
 * What the unit does after power-up (ADR-0074). Decided from what is stored,
 * never from a message, a console line or a button.
 */

typedef enum {
    /* Network settings are stored: Wi-Fi, then the broker. No setup network. */
    LC_BOOT_STATION = 0,
    /* No settings and not enrolled: the setup network opens. */
    LC_BOOT_SETUP,
    /* Enrolled but without settings: the console only. The setup network never opens. */
    LC_BOOT_BENCH,
} lc_boot_mode_t;

typedef struct {
    lc_settings_store_port_t settings_store;
    lc_identity_store_port_t identity_store;
    lc_config_store_port_t config_store;
} lc_boot_ports_t;

typedef struct {
    lc_boot_mode_t mode;
    /* A half-finished wipe was found and the repair was attempted. */
    bool repaired;
    /* The repair was attempted and a clear failed; the next boot tries again. */
    bool repair_failed;
} lc_boot_result_t;

/*
 * An empty identity (no enrollment, token or credentials; the broker session
 * identity does not count) with network settings stored is not a state any
 * normal path creates: `configure` on a fresh unit needs a token. It is what a
 * reset or a failed rollback leaves when a later clear fails. The wipe is
 * finished first (settings and mapping), then the mode is decided.
 *
 * An empty identity with a mapping and no settings is a valid bench state and
 * is left alone.
 */
lc_boot_result_t lc_boot_decide(const lc_boot_ports_t *ports);
