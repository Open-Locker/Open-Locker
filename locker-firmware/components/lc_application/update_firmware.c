#include <string.h>

#include "lc_application/app.h"
#include "lc_application/internal.h"
#include "lc_domain/text.h"
#include "lc_domain/version.h"

/* An unlock whose door is still being watched: restarting now would lose its outcome. */
static bool command_in_flight(const lc_app_t *app)
{
    for (size_t i = 0; i < app->config.count; ++i) {
        if (app->compartments[i].door.watching) {
            return true;
        }
    }
    return false;
}

/* The one response to an update_firmware command: accepted, or why not (ADR-0076). */
lc_command_result_t lc_app_update_firmware(lc_app_t *app, const lc_command_t *command)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    (void)lc_text_copy(result.transaction_id, sizeof(result.transaction_id),
                       command->transaction_id);
    (void)lc_text_copy(result.action_name, sizeof(result.action_name), command->action_name);

    const lc_firmware_update_port_t *port = &app->ports.firmware_update;
    if (app->update_running || command_in_flight(app)) {
        result.error = LC_ERROR_UPDATE_BUSY;
        return result;
    }
    /* The journal is healthy: handle_command() answers before this otherwise. */
    if (port->start == NULL || app->ports.bus.connection_state(app->ports.bus.ctx) != LC_BUS_CONNECTED ||
        port->on_trial(port->ctx)) {
        result.error = LC_ERROR_UPDATE_NOT_READY;
        return result;
    }
    const char *running = port->running_version(port->ctx);
    const lc_firmware_update_request_t *request = &command->firmware;
    if (!lc_version_is_valid(running)) {
        result.error = LC_ERROR_UPDATE_NOT_READY;
        return result;
    }
    const int order = lc_version_compare(request->version, running);
    if (order == 0) {
        result.error = LC_ERROR_UPDATE_SAME_VERSION;
        return result;
    }
    if (order < 0 && !request->allow_downgrade) {
        result.error = LC_ERROR_UPDATE_DOWNGRADE;
        return result;
    }
    if (strcmp(port->last_rollback_version(port->ctx), request->version) == 0) {
        result.error = LC_ERROR_UPDATE_VERSION_REJECTED;
        return result;
    }
    if (!port->start(port->ctx, &command->firmware)) {
        result.error = LC_ERROR_UPDATE_NOT_READY;
        return result;
    }
    app->update_running = true;
    (void)lc_text_copy(app->update_transaction_id, sizeof(app->update_transaction_id),
                       command->transaction_id);
    return result;
}

void lc_app_abort_update(lc_app_t *app)
{
    const lc_firmware_update_port_t *port = &app->ports.firmware_update;

    if (app->update_running) {
        port->abort(port->ctx);
        app->update_running = false;
    }
}

/*
 * The failure is told once, as an event: the command already has its response. It is not
 * sent again if the broker is not there; the backend's timeout covers it (ADR-0076).
 */
void lc_app_firmware_failed(lc_app_t *app, lc_error_t error)
{
    if (!app->update_running) {
        return;
    }
    app->update_running = false;

    lc_firmware_update_failure_t failure;
    memset(&failure, 0, sizeof(failure));
    (void)lc_text_copy(failure.transaction_id, sizeof(failure.transaction_id),
                       app->update_transaction_id);
    failure.error = error == LC_ERROR_UPDATE_VERIFY_FAILED ? error : LC_ERROR_UPDATE_DOWNLOAD_FAILED;
    /* A refusal is dropped (and logged by the sender). */
    lc_publish_id_t id =
        app->ports.outbound.firmware_update_failed(app->ports.outbound.ctx, &failure);
    if (id != LC_PUBLISH_REFUSED) {
        (void)lc_app_track(app, id, LC_APP_PUBLISH_TRANSIENT, "", 0);
    }
}

void lc_app_firmware_verified(lc_app_t *app)
{
    const lc_firmware_update_port_t *port = &app->ports.firmware_update;

    if (!app->update_running) {
        return;
    }
    if (port->select_next_slot(port->ctx)) {
        app->restart_pending = true;
    } else {
        lc_app_firmware_failed(app, LC_ERROR_UPDATE_VERIFY_FAILED);
    }
}

static void restart(const lc_app_t *app)
{
    app->ports.system.restart(app->ports.system.ctx);
}

void lc_app_check_restart(lc_app_t *app)
{
    if (app->restart_pending && !command_in_flight(app)) {
        app->restart_pending = false;
        restart(app);
    }
}

void lc_app_check_trial(lc_app_t *app, int64_t now_ms)
{
    const lc_firmware_update_port_t *port = &app->ports.firmware_update;

    if (!app->trial) {
        return;
    }
    if (app->network_seen && app->broker_seen && app->bus_seen && port->mark_valid(port->ctx)) {
        app->trial = false;
        return;
    }
    if (now_ms < app->trial_deadline_ms) {
        return;
    }
    /* Once: the call restarts the unit; if it returns there is no image to go back to. */
    app->trial = false;
    if (!port->rollback(port->ctx)) {
        restart(app);
    }
}

const lc_firmware_status_t *lc_app_firmware_status(const lc_app_t *app, lc_firmware_status_t *out)
{
    const lc_firmware_update_port_t *port = &app->ports.firmware_update;

    if (port->start == NULL) {
        return NULL;
    }
    memset(out, 0, sizeof(*out));
    (void)lc_text_copy(out->version, sizeof(out->version), port->running_version(port->ctx));
    out->running_slot = port->running_slot(port->ctx);
    /* From the port (the bootloader's state); app->trial only arms the checks of the trial. */
    out->trial = port->on_trial(port->ctx);
    (void)lc_text_copy(out->last_rollback_version, sizeof(out->last_rollback_version),
                       port->last_rollback_version(port->ctx));
    out->update_capable = true;
    return out;
}

