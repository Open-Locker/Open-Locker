#include "lc_application/provision_device.h"

#include <string.h>

#include "lc_domain/text.h"

void lc_provision_init(lc_provision_t *provision, const lc_provision_ports_t *ports)
{
    memset(provision, 0, sizeof(*provision));
    provision->ports = *ports;
    if (!ports->identity_store.load(ports->identity_store.ctx, &provision->identity)) {
        memset(&provision->identity, 0, sizeof(provision->identity));
    }

    if (provision->identity.enrolled) {
        provision->state = LC_PROVISION_ENROLLED;
    } else if (provision->identity.enrollment_pending) {
        /* The token left the device before the restart, and no reply was stored. */
        provision->state = LC_PROVISION_OUTCOME_UNKNOWN;
    } else {
        provision->state = LC_PROVISION_IDLE;
    }
}

bool lc_provision_wants_session(const lc_provision_t *provision)
{
    const lc_identity_t *identity = &provision->identity;
    bool enroll = provision->state == LC_PROVISION_IDLE && identity->token[0] != '\0';
    /* A late reply may be waiting at the broker; listening sends nothing. */
    bool late_reply = provision->state == LC_PROVISION_OUTCOME_UNKNOWN && identity->client_id[0] != '\0';
    return identity->enrolled || enroll || late_reply;
}

void lc_provision_on_connected(lc_provision_t *provision)
{
    const lc_provisioning_gateway_port_t *gateway = &provision->ports.gateway;

    /*
     * Waiting for the subscription: it died with the connection, so ask again.
     * Waiting for the reply: the token is out, and nothing is asked a second time.
     */
    bool can_register = provision->state == LC_PROVISION_IDLE &&
                        provision->identity.token[0] != '\0' &&
                        provision->identity.client_id[0] != '\0';
    if (can_register || provision->state == LC_PROVISION_WAITING_FOR_SUBSCRIPTION) {
        provision->state = gateway->subscribe_reply(gateway->ctx, provision->identity.client_id)
                               ? LC_PROVISION_WAITING_FOR_SUBSCRIPTION
                               : LC_PROVISION_IDLE;
    }
    if (provision->state == LC_PROVISION_OUTCOME_UNKNOWN) {
        /* Only listening: on_subscribed ignores this state, so no token is sent. */
        (void)gateway->subscribe_reply(gateway->ctx, provision->identity.client_id);
    }
}

void lc_provision_on_subscribed(lc_provision_t *provision)
{
    const lc_identity_store_port_t *store = &provision->ports.identity_store;
    const lc_provisioning_gateway_port_t *gateway = &provision->ports.gateway;

    if (provision->state != LC_PROVISION_WAITING_FOR_SUBSCRIPTION) {
        return;
    }

    /* Stored before the token leaves the device, so a restart cannot send it twice. */
    lc_identity_t pending = provision->identity;
    pending.enrollment_pending = true;
    if (!store->save(store->ctx, &pending)) {
        provision->state = LC_PROVISION_STORE_FAILED;
        return;
    }
    provision->identity = pending;

    provision->reply_deadline_ms = provision->ports.clock.now_ms(provision->ports.clock.ctx) +
                                   LC_PROVISION_REPLY_TIMEOUT_MS;
    provision->state = gateway->publish_registration(gateway->ctx, provision->identity.token,
                                                     provision->identity.client_id)
                           ? LC_PROVISION_WAITING_FOR_REPLY
                           : LC_PROVISION_OUTCOME_UNKNOWN;
}

void lc_provision_on_reply(lc_provision_t *provision, const lc_provisioning_reply_t *reply)
{
    const lc_identity_store_port_t *store = &provision->ports.identity_store;

    /* A late reply is still taken: the backend spent the token when it answered. */
    if (provision->state != LC_PROVISION_WAITING_FOR_REPLY &&
        provision->state != LC_PROVISION_OUTCOME_UNKNOWN) {
        return;
    }

    lc_identity_t next = provision->identity;
    next.enrollment_pending = false;
    next.token[0] = '\0';
    next.bootstrap_user[0] = '\0';
    next.bootstrap_password[0] = '\0';

    if (reply->success) {
        next.enrolled = true;
        (void)lc_text_copy(next.mqtt_user, sizeof(next.mqtt_user), reply->mqtt_user);
        (void)lc_text_copy(next.mqtt_password, sizeof(next.mqtt_password), reply->mqtt_password);
        (void)lc_text_copy(next.locker_uuid, sizeof(next.locker_uuid), reply->locker_uuid);
        /* One write: the credentials and the end of the enrollment are stored together. */
        if (!store->save(store->ctx, &next)) {
            provision->state = LC_PROVISION_STORE_FAILED;
            return;
        }
        provision->identity = next;
        provision->state = LC_PROVISION_ENROLLED;
        return;
    }

    /*
     * The backend refused the token, so it is spent and cannot be tried again.
     * It is cleared with the bootstrap credentials so a new one can be entered;
     * if that cannot be stored, the pending flag stays and a reset is needed.
     */
    provision->state = LC_PROVISION_REJECTED;
    if (store->save(store->ctx, &next)) {
        provision->identity = next;
    }
}

void lc_provision_tick(lc_provision_t *provision)
{
    if (provision->state == LC_PROVISION_WAITING_FOR_REPLY &&
        provision->ports.clock.now_ms(provision->ports.clock.ctx) >=
            provision->reply_deadline_ms) {
        provision->state = LC_PROVISION_OUTCOME_UNKNOWN;
    }
}

lc_provision_state_t lc_provision_state(const lc_provision_t *provision)
{
    return provision->state;
}

const lc_identity_t *lc_provision_identity(const lc_provision_t *provision)
{
    return &provision->identity;
}
