#pragma once

#include <stdbool.h>

#include "lc_domain/identity.h"

/*
 * The setup network on the ESP32 (ADR-0074): a WPA2 access point for one
 * station at a time, an HTTP transport, a Security 2 (SRP6a) session, and two
 * encrypted endpoints that carry the Wi-Fi settings and `ol-config`. It decides
 * nothing itself. Every decision comes from lc_softap_portal; every value goes
 * through its collector and out to the owner task, which runs the same
 * commissioning use case as the USB console.
 *
 * UNVERIFIED: written against the ESP-IDF 6.1 headers and compile-checked only.
 * It has not run on a board and has not been used with esp_prov (ADR-0074, open
 * points). Do not rely on it until the bench spike has confirmed it.
 */

typedef struct {
    /*
     * Both parts have arrived: hand the request to the owner task. Never blocks.
     * False when it was not accepted. The adapter then waits for
     * lc_softap_commission_result().
     */
    bool (*submit)(void *ctx, const lc_setup_request_t *request);
    void *ctx;
} lc_softap_sink_t;

/*
 * Starts the access point and opens the 10 minute window. The Wi-Fi driver
 * must be initialised (lc_wifi_init()). False when it cannot be started.
 */
bool lc_softap_start(const lc_setup_secrets_t *secrets, const lc_softap_sink_t *sink);

/*
 * The outcome of the request that was submitted, from the owner task: saved, or
 * refused or failed. A saved request closes the portal; anything else leaves it
 * open and the collected values wiped.
 */
void lc_softap_commission_result(bool saved);
