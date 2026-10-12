#include "lc_application/inbox.h"

#include <string.h>

void lc_inbox_init(lc_inbox_t *inbox)
{
    memset(inbox, 0, sizeof(*inbox));
}

bool lc_inbox_post(lc_inbox_t *inbox, const lc_event_t *event)
{
    if (inbox->count == LC_INBOX_CAPACITY) {
        /* Dropped, never run: a lost command gets no unlock and the backend times out. */
        ++inbox->dropped;
        return false;
    }
    inbox->items[(inbox->head + inbox->count) % LC_INBOX_CAPACITY] = *event;
    ++inbox->count;
    return true;
}

bool lc_inbox_step(lc_inbox_t *inbox, lc_app_t *app)
{
    if (inbox->count == 0) {
        return false;
    }
    /* Taken out first, so an event posted while it runs cannot overwrite it. */
    const lc_event_t event = inbox->items[inbox->head];
    inbox->head = (inbox->head + 1) % LC_INBOX_CAPACITY;
    --inbox->count;

    switch (event.kind) {
    case LC_EVENT_COMMAND:
        lc_app_handle_command(app, &event.command);
        break;
    case LC_EVENT_CONNECTED:
        lc_app_on_connected(app);
        break;
    case LC_EVENT_DISCONNECTED:
        lc_app_on_disconnected(app);
        break;
    case LC_EVENT_PUBLISH_ACKNOWLEDGED:
        lc_app_on_publish_acknowledged(app, event.publish_id);
        break;
    case LC_EVENT_PUBLISH_FAILED:
        lc_app_on_publish_failed(app, event.publish_id);
        break;
    case LC_EVENT_TICK:
        lc_app_tick(app);
        break;
    }
    return true;
}

uint32_t lc_inbox_dropped(const lc_inbox_t *inbox)
{
    return inbox->dropped;
}
