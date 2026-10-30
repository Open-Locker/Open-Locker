#pragma once

#include <stdbool.h>

#include "lc_domain/config.h"
#include "lc_domain/identity.h"

/* Small persistent records. `load` returns false when nothing is stored. */

typedef struct {
    void *ctx;
    bool (*load)(void *ctx, lc_config_t *out);
    bool (*save)(void *ctx, const lc_config_t *config);
    bool (*clear)(void *ctx);
} lc_config_store_port_t;

typedef struct {
    void *ctx;
    bool (*load)(void *ctx, lc_identity_t *out);
    /* One write: either all of `identity` is stored or none of it. */
    bool (*save)(void *ctx, const lc_identity_t *identity);
} lc_identity_store_port_t;

typedef struct {
    void *ctx;
    bool (*load)(void *ctx, lc_network_settings_t *out);
    bool (*save)(void *ctx, const lc_network_settings_t *settings);
    bool (*clear)(void *ctx);
} lc_settings_store_port_t;
