#pragma once

typedef struct {
    void *ctx;
    /* Requests a restart. May return; the caller must not rely on either. */
    void (*restart)(void *ctx);
} lc_system_port_t;
