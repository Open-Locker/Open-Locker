#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/command.h"
#include "lc_domain/config.h"
#include "lc_domain/door_detection.h"
#include "lc_domain/events.h"
#include "lc_ports/clock_port.h"
#include "lc_ports/command_journal_port.h"
#include "lc_ports/hasher_port.h"
#include "lc_ports/locker_bus_port.h"
#include "lc_ports/outbound_port.h"
#include "lc_ports/store_ports.h"

/*
 * The locker application: commands, configuration, door state and door
 * events. Every function is called from the single owner task, one at a time,
 * and none of them waits for the network.
 */

typedef struct {
    lc_locker_bus_port_t bus;
    lc_command_journal_port_t journal;
    lc_outbound_port_t outbound;
    lc_config_store_port_t config_store;
    lc_hasher_port_t hasher;
    lc_clock_port_t clock;
} lc_app_ports_t;

/* Publishes whose acknowledgement is still outstanding. */
#define LC_APP_OUTSTANDING_MAX 16

typedef struct {
    lc_door_tracker_t door;
    /* An uncommanded opening not yet accepted for sending. */
    bool uncommanded_due;
    lc_uncommanded_open_t uncommanded;
} lc_app_compartment_t;

typedef enum {
    LC_APP_PUBLISH_RESPONSE = 0,
    /* A response that is not in the journal, such as SHUTTING_DOWN. */
    LC_APP_PUBLISH_TRANSIENT,
    LC_APP_PUBLISH_DETECTION,
    LC_APP_PUBLISH_UNCOMMANDED,
    LC_APP_PUBLISH_SNAPSHOT,
} lc_app_publish_kind_t;

typedef struct {
    lc_publish_id_t id;
    lc_app_publish_kind_t kind;
    /* LC_APP_PUBLISH_RESPONSE */
    char transaction_id[LC_ID_MAX + 1];
    /* LC_APP_PUBLISH_DETECTION: index into the configured mappings. */
    size_t compartment_index;
} lc_app_outstanding_t;

/* Caller-allocated. Fields are private; the implementation may add to them. */
typedef struct {
    lc_app_ports_t ports;
    lc_config_t config;
    bool connected;
    bool closing;
    bool snapshot_due;
    /* The doors have been read since the configuration was applied. */
    bool settled;
    int64_t started_at_ms;
    int64_t next_poll_ms;
    int64_t next_heartbeat_ms;
    lc_app_compartment_t compartments[LC_COMPARTMENTS_MAX];
    lc_app_outstanding_t outstanding[LC_APP_OUTSTANDING_MAX];
} lc_app_t;

/* Loads the stored configuration, if any. Calls neither the bus nor outbound. */
void lc_app_init(lc_app_t *app, const lc_app_ports_t *ports);

/*
 * Run once at boot, before any command. A claim without a completion becomes
 * an unknown-outcome result. Never unlocks.
 */
void lc_app_recover(lc_app_t *app);

/*
 * The command subscription is confirmed. Publishes every response that is not
 * delivered yet and forces a snapshot. Until this is called, and after
 * lc_app_on_disconnected(), commands are not executed and nothing is published.
 */
void lc_app_on_connected(lc_app_t *app);
void lc_app_on_disconnected(lc_app_t *app);

/* A decoded command. Claims it in the journal before any side effect. */
void lc_app_handle_command(lc_app_t *app, const lc_command_t *command);

/* The broker has the message. Marks a command response as delivered. */
void lc_app_on_publish_acknowledged(lc_app_t *app, lc_publish_id_t id);
/* The message was not delivered. It stays pending and is sent again later. */
void lc_app_on_publish_failed(lc_app_t *app, lc_publish_id_t id);

/*
 * Time-driven work, called often: door polling every LC_POLL_INTERVAL_MS,
 * snapshots, door detection, heartbeat. Reads the clock port.
 */
void lc_app_tick(lc_app_t *app);

/* New commands get LC_ERROR_SHUTTING_DOWN from now on. */
void lc_app_begin_closing(lc_app_t *app);

const lc_config_t *lc_app_config(const lc_app_t *app);
