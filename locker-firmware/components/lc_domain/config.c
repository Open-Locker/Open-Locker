#include "lc_domain/config.h"

#include <stdio.h>
#include <string.h>

#include "lc_domain/text.h"

static void sort_by_number(lc_mapping_t *mappings, size_t count)
{
    for (size_t i = 1; i < count; ++i) {
        lc_mapping_t moving = mappings[i];
        size_t position = i;
        while (position > 0 && mappings[position - 1].number > moving.number) {
            mappings[position] = mappings[position - 1];
            --position;
        }
        mappings[position] = moving;
    }
}

bool lc_config_from_request(const lc_config_request_t *request, lc_config_t *out)
{
    memset(out, 0, sizeof(*out));
    if (!request->supported_adapter || request->heartbeat_seconds == 0 ||
        request->heartbeat_seconds > LC_HEARTBEAT_SECONDS_MAX ||
        request->count > LC_COMPARTMENTS_MAX) {
        return false;
    }

    for (size_t i = 0; i < request->count; ++i) {
        const lc_mapping_t *mapping = &request->mappings[i];
        if (mapping->number == 0 || mapping->board < LC_BOARD_ADDRESS_MIN ||
            mapping->board > LC_BOARD_ADDRESS_MAX ||
            mapping->address > LC_COMPARTMENT_ADDRESS_MAX) {
            return false;
        }
        /* One panel per bus: a second board address is not supported. */
        if (mapping->board != request->mappings[0].board) {
            return false;
        }
        for (size_t other = 0; other < i; ++other) {
            if (request->mappings[other].number == mapping->number ||
                request->mappings[other].address == mapping->address) {
                return false;
            }
        }
    }
    if (!lc_text_copy(out->hash, sizeof(out->hash), request->hash)) {
        return false;
    }

    out->applied = true;
    out->profile.feedback = request->feedback;
    out->heartbeat_seconds = request->heartbeat_seconds;
    out->count = request->count;
    memcpy(out->mappings, request->mappings, request->count * sizeof(request->mappings[0]));
    sort_by_number(out->mappings, out->count);
    return true;
}

bool lc_config_canonical(const lc_config_t *config, char *out, size_t capacity)
{
    size_t length = 0;
    int written = snprintf(out, capacity,
                           "{\"adapter_type\":\"rs485_lock_board\",\"feedback_type\":\"%s\","
                           "\"compartments\":[",
                           config->profile.feedback == LC_FEEDBACK_DOOR_OPENING ? "door_opening"
                                                                                : "door_closing");
    if (written < 0 || (size_t)written >= capacity) {
        return false;
    }
    length = (size_t)written;

    for (size_t i = 0; i < config->count; ++i) {
        const lc_mapping_t *mapping = &config->mappings[i];
        written = snprintf(out + length, capacity - length,
                           "%s{\"compartment_number\":%u,\"slaveId\":%u,\"address\":%u}",
                           i == 0 ? "" : ",", (unsigned)mapping->number, (unsigned)mapping->board,
                           (unsigned)mapping->address);
        if (written < 0 || (size_t)written >= capacity - length) {
            return false;
        }
        length += (size_t)written;
    }
    written = snprintf(out + length, capacity - length, "]}");
    return written >= 0 && (size_t)written < capacity - length;
}

const lc_mapping_t *lc_config_find(const lc_config_t *config, uint16_t compartment_number)
{
    for (size_t i = 0; i < config->count; ++i) {
        if (config->mappings[i].number == compartment_number) {
            return &config->mappings[i];
        }
    }
    return NULL;
}
