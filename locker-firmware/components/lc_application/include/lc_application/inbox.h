#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_application/app.h"

/*
 * The queue in front of the owner task. Driving adapters post events; the
 * owner task takes them one at a time, in arrival order. Not thread-safe by
 * itself: the composition root guards post and step.
 */

#define LC_INBOX_CAPACITY 8

typedef enum {
    LC_EVENT_COMMAND = 0,
    LC_EVENT_CONNECTED,
    LC_EVENT_DISCONNECTED,
    LC_EVENT_PUBLISH_ACKNOWLEDGED,
    LC_EVENT_PUBLISH_FAILED,
    LC_EVENT_TICK,
} lc_event_kind_t;

typedef struct {
    lc_event_kind_t kind;
    /* LC_EVENT_PUBLISH_ACKNOWLEDGED, LC_EVENT_PUBLISH_FAILED */
    lc_publish_id_t publish_id;
    /* LC_EVENT_COMMAND */
    lc_command_t command;
} lc_event_t;

typedef struct {
    lc_event_t items[LC_INBOX_CAPACITY];
    size_t head;
    size_t count;
    uint32_t dropped;
} lc_inbox_t;

void lc_inbox_init(lc_inbox_t *inbox);

/* False when the inbox is full. The event is dropped and counted. */
bool lc_inbox_post(lc_inbox_t *inbox, const lc_event_t *event);

/* Hands the oldest event to the application. False when the inbox is empty. */
bool lc_inbox_step(lc_inbox_t *inbox, lc_app_t *app);

uint32_t lc_inbox_dropped(const lc_inbox_t *inbox);
