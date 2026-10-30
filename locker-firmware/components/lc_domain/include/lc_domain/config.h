#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/compartment.h"

/* Server-managed runtime configuration (ADR-0061). */

#define LC_COMPARTMENTS_MAX 64
/* 64 hex digits and the terminator. */
#define LC_CONFIG_HASH_SIZE 65
#define LC_CONFIG_CANONICAL_MAX 4096
/* The longest interval accepted; it also bounds the door detection window. */
#define LC_HEARTBEAT_SECONDS_MAX 86400
/* Used until a configuration has been applied. */
#define LC_DEFAULT_HEARTBEAT_SECONDS 15

typedef struct {
    uint16_t number;
    uint8_t board;
    uint8_t address;
} lc_mapping_t;

/* An `apply_config` command as received, not yet validated. */
typedef struct {
    /* False for any adapter_type other than rs485_lock_board. */
    bool supported_adapter;
    lc_feedback_t feedback;
    uint32_t heartbeat_seconds;
    char hash[LC_CONFIG_HASH_SIZE];
    size_t count;
    lc_mapping_t mappings[LC_COMPARTMENTS_MAX];
} lc_config_request_t;

/* A validated configuration. `mappings` is sorted by compartment number. */
typedef struct {
    bool applied;
    lc_hardware_profile_t profile;
    uint32_t heartbeat_seconds;
    size_t count;
    lc_mapping_t mappings[LC_COMPARTMENTS_MAX];
    char hash[LC_CONFIG_HASH_SIZE];
} lc_config_t;

/*
 * Rejects an unsupported adapter, a board outside 1..31, an address above 254,
 * a compartment number of zero, duplicate numbers, duplicate addresses, a
 * second board address, and a heartbeat interval of zero. The hash is copied,
 * not checked: that needs the hasher port.
 */
bool lc_config_from_request(const lc_config_request_t *request, lc_config_t *out);

/*
 * The compact JSON whose SHA-256 is the config hash: adapter_type,
 * feedback_type, compartments, in that order. False if it does not fit.
 */
bool lc_config_canonical(const lc_config_t *config, char *out, size_t capacity);

/* NULL when the compartment is not mapped. */
const lc_mapping_t *lc_config_find(const lc_config_t *config, uint16_t compartment_number);
