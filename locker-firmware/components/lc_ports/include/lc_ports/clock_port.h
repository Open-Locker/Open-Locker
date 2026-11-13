#pragma once

#include <stdint.h>

/* Monotonic time. Never wall-clock time: it must not jump when SNTP syncs. */
typedef struct {
    void *ctx;
    int64_t (*now_ms)(void *ctx);
} lc_clock_port_t;
