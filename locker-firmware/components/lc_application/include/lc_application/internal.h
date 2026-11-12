#pragma once

#include <stdbool.h>
#include <stddef.h>

#include "lc_application/app.h"

/* Shared by the sources of the application; not part of its interface. */

/* Remembers an accepted publish until the broker acknowledges it. False when the table is full. */
bool lc_app_track(lc_app_t *app, lc_publish_id_t id, lc_app_publish_kind_t kind,
                  const char *transaction_id, size_t compartment_index);
bool lc_app_has_slot(const lc_app_t *app);
bool lc_app_in_flight(const lc_app_t *app, const char *transaction_id);

/*
 * The error schema of update_firmware allows only UPDATE_* codes, so any other
 * error of that action is reported as UPDATE_NOT_READY (ADR-0076).
 */
lc_error_t lc_app_error_for(const char *action_name, lc_error_t error);

/* A result that is not in the journal, sent once and forgotten. */
void lc_app_respond_transient(lc_app_t *app, const lc_command_t *command, lc_error_t error);
/* A journalled result. True when the publish was accepted. */
bool lc_app_publish_response(lc_app_t *app, const lc_command_result_t *result);
/* Publishes every completed response the broker has not acknowledged. */
void lc_app_flush_responses(lc_app_t *app);

lc_command_result_t lc_app_open_compartment(lc_app_t *app, const lc_command_t *command);
lc_command_result_t lc_app_apply_config(lc_app_t *app, const lc_command_t *command);
lc_command_result_t lc_app_update_firmware(lc_app_t *app, const lc_command_t *command);
/* What the heartbeat says about the firmware; NULL on a build without OTA. */
const lc_firmware_status_t *lc_app_firmware_status(const lc_app_t *app, lc_firmware_status_t *out);
/* Restarts into the selected image once no unlock is being watched. */
void lc_app_check_restart(lc_app_t *app);
/* Marks an image on trial valid once Wi-Fi, the broker and the bus have each worked. */
void lc_app_check_trial(lc_app_t *app, int64_t now_ms);
/* Stops the download of an update that was accepted but cannot be answered. */
void lc_app_abort_update(lc_app_t *app);
/* The download task's outcome. */
void lc_app_firmware_verified(lc_app_t *app);
void lc_app_firmware_failed(lc_app_t *app, lc_error_t error);

/* The work of the periodic tick, in publish_state.c. */
void lc_app_service(lc_app_t *app, int64_t now_ms);
/* The mapping changed: forget what was known about the doors. */
void lc_app_reset_doors(lc_app_t *app);
/* Sends a heartbeat when one is due, and schedules the next. */
void lc_app_send_heartbeat(lc_app_t *app, int64_t now_ms);
/* The next heartbeat is one interval from now, as after a new configuration. */
void lc_app_restart_heartbeat(lc_app_t *app, int64_t now_ms);
/* An uncommanded-open event did not reach the broker and must be sent again. */
void lc_app_republish_uncommanded(lc_app_t *app, size_t compartment_index);
/* A detection outcome did not reach the broker and must be sent again. */
void lc_app_republish_detection(lc_app_t *app, size_t compartment_index,
                                const char *transaction_id);
uint32_t lc_app_window_ms(const lc_app_t *app);
