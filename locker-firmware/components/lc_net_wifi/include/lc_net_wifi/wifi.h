#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_domain/identity.h"

/*
 * Wi-Fi station and wall-clock time. Joins the network the settings name,
 * keeps rejoining with a growing pause after a loss, and syncs the clock over
 * SNTP once it has an address. The certificate checks of the MQTT connection
 * need that clock, so nothing connects to the broker before it is valid.
 */

/* Called from the system event task when the station gains or loses its address. */
typedef void (*lc_wifi_state_cb)(void *ctx, bool up);

/* Starts the station. Returns false when it cannot be started at all. */
bool lc_wifi_start(const lc_network_settings_t *settings, lc_wifi_state_cb callback, void *ctx);

/* The clock has been set by SNTP. */
bool lc_wifi_time_valid(void);

/* Seconds since 1970-01-01 UTC. Only meaningful when lc_wifi_time_valid(). */
int64_t lc_wifi_unix_seconds(void);
