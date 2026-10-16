#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_domain/identity.h"
#include "lc_ports/clock_port.h"
#include "lc_ports/provisioning_gateway_port.h"
#include "lc_ports/store_ports.h"

/*
 * Enrollment with the backend (ADR-0048, ADR-0050). The one-time token is
 * sent at most once: the pending flag is stored before it leaves the device,
 * and a lost or rejected reply is never answered with a second attempt.
 */

#define LC_PROVISION_REPLY_TIMEOUT_MS 30000

typedef enum {
    /* Nothing to do yet: not connected, or no token. */
    LC_PROVISION_IDLE = 0,
    LC_PROVISION_WAITING_FOR_SUBSCRIPTION,
    LC_PROVISION_WAITING_FOR_REPLY,
    LC_PROVISION_ENROLLED,
    /* The backend refused the token. */
    LC_PROVISION_REJECTED,
    /* The token was sent and no usable reply was stored. Needs a reset. */
    LC_PROVISION_OUTCOME_UNKNOWN,
    /* The identity could not be stored. */
    LC_PROVISION_STORE_FAILED,
} lc_provision_state_t;

typedef struct {
    lc_provisioning_gateway_port_t gateway;
    lc_identity_store_port_t identity_store;
    lc_clock_port_t clock;
} lc_provision_ports_t;

/* Caller-allocated. Fields are private; the implementation may add to them. */
typedef struct {
    lc_provision_ports_t ports;
    lc_identity_t identity;
    lc_provision_state_t state;
    int64_t reply_deadline_ms;
} lc_provision_t;

/* Loads the stored identity. Publishes nothing. */
void lc_provision_init(lc_provision_t *provision, const lc_provision_ports_t *ports);

/* Connected with the bootstrap credentials. Asks for the reply subscription. */
void lc_provision_on_connected(lc_provision_t *provision);
/* The reply subscription is confirmed. Stores the pending flag, then registers. */
void lc_provision_on_subscribed(lc_provision_t *provision);
/* A decoded reply on this client's reply topic. */
void lc_provision_on_reply(lc_provision_t *provision, const lc_provisioning_reply_t *reply);
/* Ends the wait for a reply once LC_PROVISION_REPLY_TIMEOUT_MS has passed. */
void lc_provision_tick(lc_provision_t *provision);

lc_provision_state_t lc_provision_state(const lc_provision_t *provision);
const lc_identity_t *lc_provision_identity(const lc_provision_t *provision);
