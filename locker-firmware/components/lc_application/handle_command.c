#include <string.h>

#include "lc_application/app.h"
#include "lc_application/internal.h"
#include "lc_domain/text.h"

/* The codes the error schema of update_firmware allows, listed one by one. */
static bool is_update_refusal(lc_error_t error)
{
    switch (error) {
    case LC_ERROR_UPDATE_INVALID:
    case LC_ERROR_UPDATE_BUSY:
    case LC_ERROR_UPDATE_NOT_READY:
    case LC_ERROR_UPDATE_SAME_VERSION:
    case LC_ERROR_UPDATE_DOWNGRADE:
    case LC_ERROR_UPDATE_VERSION_REJECTED:
        return true;
    case LC_ERROR_NONE:
    case LC_ERROR_MISSING_MESSAGE_ID:
    case LC_ERROR_INVALID_COMMAND:
    case LC_ERROR_UNKNOWN_ACTION:
    case LC_ERROR_INVALID_CONFIG:
    case LC_ERROR_RUNTIME_CONFIG_NOT_APPLIED:
    case LC_ERROR_COMPARTMENT_NOT_FOUND:
    case LC_ERROR_HARDWARE_ERROR:
    case LC_ERROR_SHUTTING_DOWN:
    case LC_ERROR_UPDATE_DOWNLOAD_FAILED:
    case LC_ERROR_UPDATE_VERIFY_FAILED:
    case LC_ERROR_UNKNOWN_ERROR:
    default:
        return false;
    }
}

lc_error_t lc_app_error_for(const char *action_name, lc_error_t error)
{
    if (strcmp(action_name, "update_firmware") == 0 && error != LC_ERROR_NONE &&
        !is_update_refusal(error)) {
        return LC_ERROR_UPDATE_NOT_READY;
    }
    return error;
}

static lc_command_result_t result_for(const lc_command_t *command, lc_error_t error)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    (void)lc_text_copy(result.transaction_id, sizeof(result.transaction_id),
                       command->transaction_id);
    (void)lc_text_copy(result.action_name, sizeof(result.action_name), command->action_name);
    result.error = lc_app_error_for(command->action_name, error);
    return result;
}

bool lc_app_publish_response(lc_app_t *app, const lc_command_result_t *result)
{
    if (!app->connected || !lc_app_has_slot(app)) {
        return false;
    }
    lc_publish_id_t id = app->ports.outbound.command_response(app->ports.outbound.ctx, result);
    if (id == LC_PUBLISH_REFUSED) {
        return false;
    }
    return lc_app_track(app, id, LC_APP_PUBLISH_RESPONSE, result->transaction_id, 0);
}

void lc_app_flush_responses(lc_app_t *app)
{
    const lc_command_journal_port_t *journal = &app->ports.journal;

    if (!app->connected) {
        return;
    }
    for (size_t i = 0; i < journal->count(journal->ctx); ++i) {
        lc_journal_entry_t entry;
        if (!journal->at(journal->ctx, i, &entry) || !entry.completed || entry.delivered ||
            lc_app_in_flight(app, entry.transaction_id)) {
            continue;
        }
        if (!lc_app_publish_response(app, &entry.result)) {
            /* The sender is not taking messages; try again at the next service. */
            return;
        }
    }
}

void lc_app_respond_transient(lc_app_t *app, const lc_command_t *command, lc_error_t error)
{
    const lc_command_result_t result = result_for(command, error);

    if (!lc_app_has_slot(app)) {
        return;
    }
    lc_publish_id_t id = app->ports.outbound.command_response(app->ports.outbound.ctx, &result);
    if (id != LC_PUBLISH_REFUSED) {
        (void)lc_app_track(app, id, LC_APP_PUBLISH_TRANSIENT, "", 0);
    }
}

static lc_command_result_t execute(lc_app_t *app, const lc_command_t *command)
{
    switch (command->action) {
    case LC_ACTION_OPEN_COMPARTMENT:
        return lc_app_open_compartment(app, command);
    case LC_ACTION_APPLY_CONFIG:
        return lc_app_apply_config(app, command);
    case LC_ACTION_UPDATE_FIRMWARE:
        return lc_app_update_firmware(app, command);
    case LC_ACTION_UNKNOWN:
    default:
        return result_for(command, LC_ERROR_UNKNOWN_ACTION);
    }
}

void lc_app_handle_command(lc_app_t *app, const lc_command_t *command)
{
    const lc_command_journal_port_t *journal = &app->ports.journal;
    lc_journal_entry_t entry;

    /* Until the command subscription is confirmed nothing is executed. */
    if (!app->connected) {
        return;
    }
    if (app->closing) {
        lc_app_respond_transient(app, command, LC_ERROR_SHUTTING_DOWN);
        return;
    }
    /* A command that cannot be executed as received has no side effect to guard. */
    if (command->defect != LC_ERROR_NONE) {
        lc_app_respond_transient(app, command, command->defect);
        return;
    }
    /* No side effect to guard, so it needs no journal slot either. */
    if (command->action == LC_ACTION_UNKNOWN) {
        lc_app_respond_transient(app, command, LC_ERROR_UNKNOWN_ACTION);
        return;
    }
    if (!journal->healthy(journal->ctx)) {
        lc_app_respond_transient(app, command, LC_ERROR_UNKNOWN_ERROR);
        return;
    }

    if (journal->seen_message(journal->ctx, command->message_id)) {
        return;
    }
    if (journal->find(journal->ctx, command->transaction_id, &entry)) {
        (void)journal->remember_message(journal->ctx, command->transaction_id,
                                        command->message_id);
        /* Completed: the stored answer again. Incomplete: it is still being worked on. */
        if (entry.completed && !lc_app_in_flight(app, entry.transaction_id)) {
            (void)lc_app_publish_response(app, &entry.result);
        }
        return;
    }

    /* The claim is durable before anything can happen on the bus. */
    if (!journal->claim(journal->ctx, command->transaction_id, command->message_id,
                        command->action_name)) {
        lc_app_respond_transient(app, command, LC_ERROR_UNKNOWN_ERROR);
        return;
    }

    const lc_command_result_t result = execute(app, command);

    /*
     * If the outcome cannot be stored the claim stays, so the command is never
     * run again; nothing is published that the journal does not hold.
     */
    if (!journal->complete(journal->ctx, &result)) {
        /* An accepted update would download with no answer stored, and install. */
        if (command->action == LC_ACTION_UPDATE_FIRMWARE && result.error == LC_ERROR_NONE) {
            lc_app_abort_update(app);
        }
        return;
    }
    (void)lc_app_publish_response(app, &result);
}
