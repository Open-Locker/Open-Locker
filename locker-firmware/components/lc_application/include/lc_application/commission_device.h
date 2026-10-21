#pragma once

#include <stdbool.h>

#include "lc_domain/identity.h"
#include "lc_ports/outbound_port.h"
#include "lc_ports/store_ports.h"
#include "lc_ports/system_port.h"

/*
 * Local setup of the device: network settings and, before enrollment, the
 * bootstrap credentials and the one-time token. The channel the settings
 * arrive on (USB console, setup portal) is a driving adapter.
 */

typedef enum {
    LC_COMMISSION_SAVED = 0,
    LC_COMMISSION_INVALID,
    /* An enrollment is pending; only a reset is accepted. */
    LC_COMMISSION_RESET_REQUIRED,
    LC_COMMISSION_SAVE_FAILED,
} lc_commission_result_t;

typedef struct {
    lc_settings_store_port_t settings_store;
    lc_identity_store_port_t identity_store;
    lc_config_store_port_t config_store;
    lc_outbound_port_t outbound;
    lc_system_port_t system;
    void *ctx;
    /* The application must refuse commands from now on. */
    void (*stop_commands)(void *ctx);
    /* Tells the installer the outcome. Never carries a submitted value. */
    void (*reply)(void *ctx, lc_commission_result_t result);
} lc_commission_ports_t;

/* Caller-allocated. Fields are private; the implementation may add to them. */
typedef struct {
    lc_commission_ports_t ports;
    bool online;
    bool allow_plaintext_broker;
} lc_commission_t;

void lc_commission_init(lc_commission_t *commission, const lc_commission_ports_t *ports);

/* Whether the device is connected with its issued identity. */
void lc_commission_set_online(lc_commission_t *commission, bool online);

/* Development builds only: also accept an mqtt:// broker URL (ADR-0069). Off by default. */
void lc_commission_allow_plaintext_broker(lc_commission_t *commission, bool allow);

/*
 * Validates, stores, replies, then requests a restart, in that order. On an
 * enrolled device only the network settings are taken; identity, mapping and
 * journal are left alone.
 */
lc_commission_result_t lc_commission_apply(lc_commission_t *commission,
                                           const lc_setup_request_t *request);

/*
 * Clears network settings, identity and mapping, then requests a restart. The
 * command journal is kept.
 */
bool lc_commission_reset(lc_commission_t *commission);
