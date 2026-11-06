#pragma once

#include <stdbool.h>

#include "lc_domain/identity.h"

/*
 * The JSON of `configure`:
 *
 *   {"ssid": "...", "wifi_password": "...", "broker": "mqtts://...",
 *    "bootstrap_user": "...", "bootstrap_password": "...", "token": "..."}
 *
 * Every member is a string that fits its field. A missing member is empty and
 * an unknown or repeated one is an error. Whether the values make sense is for
 * the commissioning use case to say. Nothing of the input is ever reported.
 */
bool lc_console_parse_setup(const char *json, lc_setup_request_t *out);
