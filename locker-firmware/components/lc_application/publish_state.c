#include <string.h>

#include "lc_application/app.h"
#include "lc_application/internal.h"

/* ---- Heartbeat ---- */

static uint32_t heartbeat_ms(const lc_app_t *app)
{
    uint32_t seconds =
        app->config.applied ? app->config.heartbeat_seconds : LC_DEFAULT_HEARTBEAT_SECONDS;
    return seconds * 1000u;
}

void lc_app_restart_heartbeat(lc_app_t *app, int64_t now_ms)
{
    app->next_heartbeat_ms = now_ms + heartbeat_ms(app);
}

void lc_app_send_heartbeat(lc_app_t *app, int64_t now_ms)
{
    if (!app->connected || now_ms < app->next_heartbeat_ms) {
        return;
    }
    app->next_heartbeat_ms = now_ms + heartbeat_ms(app);

    uint32_t uptime_seconds = (uint32_t)((now_ms - app->started_at_ms) / 1000);
    bool bus_connected = app->ports.bus.connection_state(app->ports.bus.ctx) == LC_BUS_CONNECTED;
    /* Not tracked: the next one follows in an interval whether or not this arrived. */
    lc_firmware_status_t firmware;
    (void)app->ports.outbound.heartbeat(app->ports.outbound.ctx, uptime_seconds, bus_connected,
                                        lc_app_firmware_status(app, &firmware));
}

/* ---- Doors ---- */

void lc_app_reset_doors(lc_app_t *app)
{
    for (size_t i = 0; i < LC_COMPARTMENTS_MAX; ++i) {
        memset(&app->compartments[i], 0, sizeof(app->compartments[i]));
    }
    app->settled = false;
    app->snapshot_due = true;
    app->next_poll_ms = 0;
}

void lc_app_republish_detection(lc_app_t *app, size_t compartment_index,
                                const char *transaction_id)
{
    if (compartment_index >= app->config.count) {
        return;
    }
    lc_door_tracker_t *door = &app->compartments[compartment_index].door;
    /* Only the outcome that was lost; a newer unlock has its own. */
    if (!door->watching && strcmp(door->outcome.transaction_id, transaction_id) == 0) {
        door->outcome_due = true;
    }
}

void lc_app_republish_uncommanded(lc_app_t *app, size_t compartment_index)
{
    if (compartment_index < app->config.count) {
        app->compartments[compartment_index].uncommanded_due = true;
    }
}

/* Reads every mapped door once and runs each through its tracker. */
static void poll_doors(lc_app_t *app, int64_t now_ms)
{
    uint8_t addresses[LC_COMPARTMENTS_MAX];
    lc_door_t readings[LC_COMPARTMENTS_MAX];
    size_t count = app->config.count;

    if (count == 0) {
        return;
    }
    for (size_t i = 0; i < count; ++i) {
        addresses[i] = app->config.mappings[i].address;
        readings[i] = LC_DOOR_UNKNOWN;
    }
    if (!app->ports.bus.read_compartment_states(app->ports.bus.ctx, app->config.mappings[0].board,
                                                addresses, count, readings)) {
        for (size_t i = 0; i < count; ++i) {
            readings[i] = LC_DOOR_UNKNOWN;
        }
    } else {
        app->bus_seen = true;
    }

    for (size_t i = 0; i < count; ++i) {
        lc_app_compartment_t *compartment = &app->compartments[i];
        lc_door_update_t update = lc_door_update(&compartment->door, app->config.mappings[i].number,
                                                 readings[i], now_ms, lc_app_window_ms(app));
        if (update.changed) {
            app->snapshot_due = true;
        }
        if (update.uncommanded) {
            compartment->uncommanded = update.event;
            compartment->uncommanded_due = true;
        }
        /* Something was read, or the panel has been silent long enough to say so. */
        if (readings[i] != LC_DOOR_UNKNOWN ||
            compartment->door.unknown_reads >= LC_UNKNOWN_READS_BEFORE_UNKNOWN) {
            app->settled = true;
        }
    }
}

/* ---- Publishing ---- */

static void publish_detections(lc_app_t *app)
{
    for (size_t i = 0; i < app->config.count; ++i) {
        lc_door_tracker_t *door = &app->compartments[i].door;
        if (!door->outcome_due || !lc_app_has_slot(app)) {
            continue;
        }
        lc_publish_id_t id =
            app->ports.outbound.open_detection(app->ports.outbound.ctx, &door->outcome);
        if (id != LC_PUBLISH_REFUSED &&
            lc_app_track(app, id, LC_APP_PUBLISH_DETECTION, door->outcome.transaction_id, i)) {
            door->outcome_due = false;
        }
    }
}

static void publish_uncommanded(lc_app_t *app)
{
    for (size_t i = 0; i < app->config.count; ++i) {
        lc_app_compartment_t *compartment = &app->compartments[i];
        if (!compartment->uncommanded_due || !lc_app_has_slot(app)) {
            continue;
        }
        lc_publish_id_t id =
            app->ports.outbound.uncommanded_open(app->ports.outbound.ctx, &compartment->uncommanded);
        if (id != LC_PUBLISH_REFUSED && lc_app_track(app, id, LC_APP_PUBLISH_UNCOMMANDED, "", i)) {
            compartment->uncommanded_due = false;
        }
    }
}

static void publish_snapshot(lc_app_t *app)
{
    lc_compartment_state_t states[LC_COMPARTMENTS_MAX];

    if (!app->snapshot_due || !app->settled || !lc_app_has_slot(app)) {
        return;
    }
    for (size_t i = 0; i < app->config.count; ++i) {
        states[i].compartment_number = app->config.mappings[i].number;
        states[i].state = app->compartments[i].door.reported;
    }
    lc_publish_id_t id = app->ports.outbound.compartment_snapshot(app->ports.outbound.ctx, states,
                                                                  app->config.count);
    if (id != LC_PUBLISH_REFUSED && lc_app_track(app, id, LC_APP_PUBLISH_SNAPSHOT, "", 0)) {
        app->snapshot_due = false;
    }
}

void lc_app_service(lc_app_t *app, int64_t now_ms)
{
    lc_app_flush_responses(app);
    if (app->config.applied) {
        poll_doors(app, now_ms);
    }
    if (app->connected) {
        publish_detections(app);
        publish_uncommanded(app);
        publish_snapshot(app);
    }
}
