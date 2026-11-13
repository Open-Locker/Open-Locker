#pragma once

#include <stdbool.h>
#include <stdint.h>

#include "lc_domain/config.h"
#include "lc_domain/errors.h"

/* A command from the backend and the result the device reports for it. */

#define LC_ID_MAX 128
#define LC_ACTION_NAME_MAX 31

typedef enum {
    LC_ACTION_UNKNOWN = 0,
    LC_ACTION_OPEN_COMPARTMENT,
    LC_ACTION_APPLY_CONFIG,
} lc_action_t;

typedef struct {
    char transaction_id[LC_ID_MAX + 1];
    char message_id[LC_ID_MAX + 1];
    /* As received, so an unknown action can be echoed in the response. */
    char action_name[LC_ACTION_NAME_MAX + 1];
    lc_action_t action;
    /* Set by the decoder when the command cannot be executed as received. */
    lc_error_t defect;
    /* LC_ACTION_OPEN_COMPARTMENT */
    uint16_t compartment_number;
    /* LC_ACTION_APPLY_CONFIG */
    lc_config_request_t config;
} lc_command_t;

typedef struct {
    char transaction_id[LC_ID_MAX + 1];
    char action_name[LC_ACTION_NAME_MAX + 1];
    /* LC_ERROR_NONE means success. */
    lc_error_t error;
    /* Empty unless an apply_config succeeded. */
    char applied_config_hash[LC_CONFIG_HASH_SIZE];
} lc_command_result_t;
