#pragma once

#include <stdbool.h>
#include <stddef.h>

#include "lc_domain/command.h"

/*
 * Durable record of every command: claimed before any side effect, completed
 * before the response is published, delivered once the broker has it. Every
 * function returns only when the record is committed, or false.
 */

typedef struct {
    char transaction_id[LC_ID_MAX + 1];
    char action_name[LC_ACTION_NAME_MAX + 1];
    bool completed;
    bool delivered;
    /* Valid when `completed`. */
    lc_command_result_t result;
} lc_journal_entry_t;

typedef struct {
    void *ctx;
    /* False once any record was found corrupt. Nothing may actuate then. */
    bool (*healthy)(void *ctx);
    bool (*seen_message)(void *ctx, const char *message_id);
    /* False when the transaction is unknown. */
    bool (*find)(void *ctx, const char *transaction_id, lc_journal_entry_t *out);
    /* Refused when there is no room left for its completion and delivery. */
    bool (*claim)(void *ctx, const char *transaction_id, const char *message_id,
                  const char *action_name);
    /* Records a further message id for a transaction that is already claimed. */
    bool (*remember_message)(void *ctx, const char *transaction_id, const char *message_id);
    bool (*complete)(void *ctx, const lc_command_result_t *result);
    bool (*mark_delivered)(void *ctx, const char *transaction_id);
    size_t (*count)(void *ctx);
    /* Entries in claim order. */
    bool (*at)(void *ctx, size_t index, lc_journal_entry_t *out);
} lc_command_journal_port_t;
