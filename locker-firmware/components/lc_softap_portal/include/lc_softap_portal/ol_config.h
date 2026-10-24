#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/identity.h"

/*
 * What the installer's laptop sends through the setup network, collected into
 * the same lc_setup_request_t the USB console fills (ADR-0074). Two parts
 * arrive: the Wi-Fi settings from the provisioning manager's own endpoint, and
 * `ol-config` from our custom endpoint. Nothing is handed on until both are
 * here and well formed, so the settings are saved together or not at all.
 *
 * `ol-config` v1 is a version byte (1) followed by tag-length-value items, one
 * length byte each, no padding:
 *
 *   tag 1 broker URL, tag 2 bootstrap user, tag 3 bootstrap password, tag 4 token
 *
 * Every tag appears exactly once, with a non-empty value that fits its field
 * and holds no NUL byte. Anything else is refused: unknown or repeated tags, a
 * length past the end, bytes left over, a payload over LC_OL_CONFIG_MAX.
 *
 * Whether the values make sense (an mqtts:// URL, a Wi-Fi password in bounds)
 * is for the commissioning use case to say, as for USB. Nothing of the input is
 * ever reported back: the only reply is one status byte.
 */

#define LC_OL_CONFIG_VERSION 1
/* Version byte, four headers, and the largest value of each field. */
#define LC_OL_CONFIG_MAX \
    (1 + 4 * 2 + LC_BROKER_URL_MAX + 3 * LC_CREDENTIAL_MAX)

typedef enum {
    LC_OL_CONFIG_REPLY_OK = 0x00,
    /* One code for every refusal, so a reply tells nothing about the input. */
    LC_OL_CONFIG_REPLY_REFUSED = 0x01,
} lc_ol_config_reply_t;

/* Caller-allocated. Fields are private. */
typedef struct {
    lc_setup_request_t request;
    bool have_wifi;
    bool have_config;
} lc_collector_t;

void lc_collector_init(lc_collector_t *collector);

/*
 * The Wi-Fi settings, as the provisioning manager hands them over: byte strings
 * with a length. Refused when too long for the field or holding a NUL.
 */
bool lc_collector_wifi(lc_collector_t *collector, const uint8_t *ssid, size_t ssid_length,
                       const uint8_t *password, size_t password_length);

/* The `ol-config` payload. A refused payload leaves nothing of it behind. */
bool lc_collector_ol_config(lc_collector_t *collector, const uint8_t *payload, size_t length);

/* Both parts are in. */
bool lc_collector_ready(const lc_collector_t *collector);

/*
 * Moves the collected request out and wipes the collector. False, and `out`
 * zeroed, when a part is missing.
 */
bool lc_collector_take(lc_collector_t *collector, lc_setup_request_t *out);

/* Wipes everything collected so far. */
void lc_collector_clear(lc_collector_t *collector);

/* The reply to an `ol-config` request: always exactly one byte. */
size_t lc_ol_config_reply(bool accepted, uint8_t out[1]);
