#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_application/app.h"
#include "lc_application/commission_device.h"
#include "lc_application/provision_device.h"
#include "lc_domain/identity.h"
#include "lc_journal/journal.h"
#include "lc_ports/locker_bus_port.h"
#include "lc_ports/outbound_port.h"
#include "lc_ports/provisioning_gateway_port.h"
#include "lc_ports/store_ports.h"
#include "lc_ports/system_port.h"

/*
 * What the files of the composition root share. Every use case runs on one
 * task, the owner task; everything else posts events to it.
 */

typedef enum {
    /* An event of the application: a command, an acknowledgement, a connection change. */
    OWNER_APP = 0,
    /* The station has an address. */
    OWNER_NETWORK_UP,
    OWNER_NETWORK_DOWN,
    /* The MQTT connection to the broker is up or down. */
    OWNER_TRANSPORT_CONNECTED,
    OWNER_TRANSPORT_DISCONNECTED,
    /* The broker confirmed a subscription. */
    OWNER_SUBSCRIBED,
    OWNER_PROVISION_REPLY,
    /* From the console: `configure`, `reset-network`. */
    OWNER_SETUP,
    OWNER_RESET,
} owner_event_kind_t;

typedef struct {
    owner_event_kind_t kind;
    /* For OWNER_SETUP: where the one reply goes. */
    lc_commission_channel_t channel;
    union {
        lc_event_t app;
        int message_id;
        lc_provisioning_reply_t reply;
        lc_setup_request_t setup;
    } u;
} owner_event_t;

/* From any task. False, and the event is lost, when the queue is full. */
bool owner_post(const owner_event_t *event);
bool owner_post_app(const lc_event_t *event);

/* What the console says about the device. Written by the owner task. */
typedef struct {
    volatile bool enrolled;
    volatile bool pending;
    volatile bool configured;
} device_state_t;

extern device_state_t device_state;

/* Everything the owner task works with. All pointers stay valid for good. */
typedef struct {
    lc_app_t *app;
    lc_provision_t *provision;
    lc_commission_t *commission;
    /* Its background work runs on idle ticks; the app reaches it only through its port. */
    lc_journal_t *journal;
    lc_settings_store_port_t settings_store;
    lc_system_port_t system;
    /* False on a device that has no network settings: commands then come from the console. */
    bool bench_mode;
} owner_context_t;

/* Creates the queue and starts the owner task. */
bool owner_start(const owner_context_t *context);

/* The owner task's stack that was never used, in bytes. False before the task runs. */
bool owner_stack_free(uint32_t *bytes);

/* Starts the reset button task (button_task.c); it posts OWNER_RESET. */
bool button_task_start(void);

/* ---- The MQTT session (mqtt_session.c) ---- */

typedef struct {
    char broker_uri[LC_BROKER_URL_MAX + 1];
    char client_id[LC_CLIENT_ID_MAX + 1];
    char username[LC_CREDENTIAL_MAX + 1];
    char password[LC_CREDENTIAL_MAX + 1];
    /* Empty until the device is enrolled. */
    char locker_uuid[LC_UUID_SIZE];
} mqtt_session_config_t;

bool mqtt_session_start(const mqtt_session_config_t *config);
/* Asks for the commands of this locker. Returns the subscription id, negative on failure. */
int mqtt_session_subscribe_commands(void);
lc_outbound_port_t mqtt_session_outbound(void);
lc_provisioning_gateway_port_t mqtt_session_gateway(void);
/* The id returned by the last gateway subscribe_reply(), or negative. */
int mqtt_session_reply_subscription(void);

/* ---- Bench mode (bench.c): replies are printed to the console ---- */

lc_outbound_port_t bench_outbound(void);

/* ---- The bus, behind a lock (bus_guard.c) ---- */

/*
 * The console and the owner task both use the bus. The guard lets one request
 * finish before the next is written; the bus adapter itself is not re-entrant.
 */
lc_locker_bus_port_t bus_guard(const lc_locker_bus_port_t *bus);
