#pragma once

#include <stdbool.h>

/* The broker side of enrollment (ADR-0048). */
typedef struct {
    void *ctx;
    /* Asks for the reply topic of this client. Confirmation arrives as an event. */
    bool (*subscribe_reply)(void *ctx, const char *client_id);
    /* Sends the one-time token. Must be called at most once per token. */
    bool (*publish_registration)(void *ctx, const char *token, const char *client_id);
} lc_provisioning_gateway_port_t;
