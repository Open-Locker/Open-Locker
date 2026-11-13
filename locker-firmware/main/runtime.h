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
#include "owner_queue.h"

/*
 * What the files of the composition root share. Every use case runs on one
 * task, the owner task; everything else posts to its inputs (owner_queue.h).
 */

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

/* Creates its inputs and starts the owner task. */
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
