#pragma once

#include <stdbool.h>

#define LC_SHA256_HEX_SIZE 65

typedef struct {
    void *ctx;
    /* Lower-case hex SHA-256 of a NUL-terminated text. */
    bool (*sha256_hex)(void *ctx, const char *text, char out[LC_SHA256_HEX_SIZE]);
} lc_hasher_port_t;
