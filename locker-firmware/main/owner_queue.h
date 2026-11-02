#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_application/app.h"
#include "lc_application/commission_device.h"
#include "lc_domain/command.h"
#include "lc_domain/identity.h"

/*
 * What feeds the owner task. Four inputs, so that a burst of one kind cannot
 * push out another:
 *   - the control queue: small events (acknowledgements, connection changes);
 *   - the command pool: commands, decoded in place, never copied;
 *   - the setup mailbox and the provisioning reply mailbox: one slot each.
 * Every post and every acquire is non-blocking. When there is no room the event
 * is lost, and a lost command is never claimed and never unlocks.
 */

typedef enum {
    /* An event of the application: an acknowledgement, a download result, a connection change. */
    OWNER_APP = 0,
    /* The station has an address. */
    OWNER_NETWORK_UP,
    OWNER_NETWORK_DOWN,
    /* The MQTT connection to the broker is up or down. */
    OWNER_TRANSPORT_CONNECTED,
    OWNER_TRANSPORT_DISCONNECTED,
    /* The broker confirmed a subscription; `value` is its message id. */
    OWNER_SUBSCRIBED,
    OWNER_RESET,
} owner_event_kind_t;

typedef struct {
    owner_event_kind_t kind;
    /* OWNER_APP: the application's event kind, and its publish id or error as `value`. */
    lc_event_kind_t app_kind;
    uint32_t value;
} owner_control_t;

/* From the console or the setup network: where the one reply goes, and what was submitted. */
typedef struct {
    lc_commission_channel_t channel;
    lc_setup_request_t request;
} owner_setup_t;

#define OWNER_CONTROL_DEPTH 32
#define OWNER_COMMAND_SLOTS 32
/*
 * What the four inputs hold, counted together: the control events, the command
 * slots, the two mailboxes, and the one-byte slot indices of the free and ready
 * queues of the three slot sets. Not counted: the FreeRTOS control block of each
 * of the seven queues and the semaphore (about 84 bytes each, from the bench
 * spike, ADR-0074 open points). A type that outgrows the budget fails the build.
 */
#define OWNER_QUEUE_BUDGET_BYTES (24 * 1024)
#define OWNER_INDEX_BYTES (2 * (OWNER_COMMAND_SLOTS + 1 + 1))
#define OWNER_QUEUE_BYTES                                                                       \
    (sizeof(owner_control_t) * OWNER_CONTROL_DEPTH + sizeof(lc_command_t) * OWNER_COMMAND_SLOTS + \
     sizeof(owner_setup_t) + sizeof(lc_provisioning_reply_t) + OWNER_INDEX_BYTES)
_Static_assert(OWNER_QUEUE_BYTES <= OWNER_QUEUE_BUDGET_BYTES,
               "the owner task's inputs outgrew their memory budget; lower OWNER_COMMAND_SLOTS");

/* False when a queue could not be created. */
bool owner_queue_init(void);

/* From any task. False, and the event is lost, when the control queue is full. */
bool owner_post(const owner_control_t *event);
bool owner_post_app(lc_event_kind_t kind, uint32_t value);

/*
 * A command slot, zeroed. NULL, logged, when all are in use. Decode into it and
 * submit it, or discard it when there is nothing to hand on. A submit that is
 * refused gives the slot back itself.
 */
lc_command_t *owner_command_acquire(void);
bool owner_command_submit(lc_command_t *slot);
void owner_command_discard(lc_command_t *slot);

/* Copies the request. False when the previous one is not handled yet. */
bool owner_post_setup(lc_commission_channel_t channel, const lc_setup_request_t *request);

/* The same as for a command, for the provisioning reply. */
lc_provisioning_reply_t *owner_reply_acquire(void);
bool owner_reply_submit(void);
void owner_reply_discard(lc_provisioning_reply_t *slot);

/* What the owner task does with each input. Settings and tokens are zeroed after the call. */
typedef struct {
    void (*control)(const owner_control_t *event);
    void (*command)(const lc_command_t *command);
    void (*setup)(const owner_setup_t *setup);
    void (*reply)(const lc_provisioning_reply_t *reply);
} owner_handlers_t;

/*
 * Handles what is waiting: control events first (they free tracking slots and
 * journal entries; at most OWNER_CONTROL_DEPTH, so a flood cannot starve the
 * rest), then the setup and the reply, then one command.
 * When nothing was waiting, sleeps up to `wait_ms` for a post and looks again. A
 * wake that finds nothing (a post that an earlier step already handled) goes
 * back to sleep for the rest of the time. True when anything was handled; false
 * means the whole time passed with nothing posted, so the task was idle.
 * Owner task only.
 */
bool owner_queue_step(const owner_handlers_t *handlers, uint32_t wait_ms);
