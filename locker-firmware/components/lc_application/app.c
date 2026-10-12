#include <string.h>

#include "lc_application/app.h"
#include "lc_application/internal.h"
#include "lc_domain/text.h"

bool lc_app_has_slot(const lc_app_t *app)
{
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        if (app->outstanding[i].id == LC_PUBLISH_REFUSED) {
            return true;
        }
    }
    return false;
}

bool lc_app_track(lc_app_t *app, lc_publish_id_t id, lc_app_publish_kind_t kind,
                  const char *transaction_id, size_t compartment_index)
{
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        lc_app_outstanding_t *slot = &app->outstanding[i];
        if (slot->id == LC_PUBLISH_REFUSED) {
            memset(slot, 0, sizeof(*slot));
            slot->id = id;
            slot->kind = kind;
            slot->sent_at_ms = app->ports.clock.now_ms(app->ports.clock.ctx);
            slot->compartment_index = compartment_index;
            (void)lc_text_copy(slot->transaction_id, sizeof(slot->transaction_id), transaction_id);
            return true;
        }
    }
    return false;
}

bool lc_app_in_flight(const lc_app_t *app, const char *transaction_id)
{
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        const lc_app_outstanding_t *slot = &app->outstanding[i];
        if (slot->id != LC_PUBLISH_REFUSED && slot->kind == LC_APP_PUBLISH_RESPONSE &&
            strcmp(slot->transaction_id, transaction_id) == 0) {
            return true;
        }
    }
    return false;
}

uint32_t lc_app_window_ms(const lc_app_t *app)
{
    return app->config.heartbeat_seconds * 1000u;
}

void lc_app_init(lc_app_t *app, const lc_app_ports_t *ports)
{
    memset(app, 0, sizeof(*app));
    app->ports = *ports;
    app->started_at_ms = ports->clock.now_ms(ports->clock.ctx);
    if (!ports->config_store.load(ports->config_store.ctx, &app->config) ||
        !app->config.applied) {
        memset(&app->config, 0, sizeof(app->config));
    }
}

void lc_app_recover(lc_app_t *app)
{
    const lc_command_journal_port_t *journal = &app->ports.journal;

    if (app->config.applied) {
        (void)app->ports.bus.apply_profile(app->ports.bus.ctx, &app->config.profile);
    }

    /* The claim was committed and the outcome never was: it is not known. */
    for (size_t i = 0; i < journal->count(journal->ctx); ++i) {
        lc_journal_entry_t entry;
        if (!journal->at(journal->ctx, i, &entry) || entry.completed) {
            continue;
        }
        lc_command_result_t result;
        memset(&result, 0, sizeof(result));
        (void)lc_text_copy(result.transaction_id, sizeof(result.transaction_id),
                           entry.transaction_id);
        (void)lc_text_copy(result.action_name, sizeof(result.action_name), entry.action_name);
        result.error = LC_ERROR_UNKNOWN_ERROR;
        (void)journal->complete(journal->ctx, &result);
    }
}

/* A publish that will not be acknowledged: what it carried is due again. */
static void lose(lc_app_t *app, const lc_app_outstanding_t *slot)
{
    switch (slot->kind) {
    case LC_APP_PUBLISH_DETECTION:
        lc_app_republish_detection(app, slot->compartment_index, slot->transaction_id);
        break;
    case LC_APP_PUBLISH_UNCOMMANDED:
        lc_app_republish_uncommanded(app, slot->compartment_index);
        break;
    case LC_APP_PUBLISH_SNAPSHOT:
        app->snapshot_due = true;
        break;
    case LC_APP_PUBLISH_RESPONSE:
    case LC_APP_PUBLISH_TRANSIENT:
    default:
        break;
    }
}

void lc_app_on_connected(lc_app_t *app)
{
    app->connected = true;
    app->snapshot_due = true;
    /* Tell the backend at once that the device is back. */
    app->next_heartbeat_ms = 0;
    lc_app_flush_responses(app);
}

void lc_app_on_disconnected(lc_app_t *app)
{
    app->connected = false;
    /* What was in flight is lost with the connection and is sent again later. */
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        lc_app_outstanding_t *slot = &app->outstanding[i];
        if (slot->id != LC_PUBLISH_REFUSED) {
            lose(app, slot);
        }
        memset(slot, 0, sizeof(*slot));
    }
}

void lc_app_on_publish_acknowledged(lc_app_t *app, lc_publish_id_t id)
{
    if (id == LC_PUBLISH_REFUSED) {
        return;
    }
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        lc_app_outstanding_t *slot = &app->outstanding[i];
        if (slot->id != id) {
            continue;
        }
        if (slot->kind == LC_APP_PUBLISH_RESPONSE) {
            const lc_command_journal_port_t *journal = &app->ports.journal;
            lc_journal_entry_t entry;
            if (journal->find(journal->ctx, slot->transaction_id, &entry) && entry.completed &&
                !entry.delivered) {
                (void)journal->mark_delivered(journal->ctx, slot->transaction_id);
            }
        }
        memset(slot, 0, sizeof(*slot));
        return;
    }
}

void lc_app_on_publish_failed(lc_app_t *app, lc_publish_id_t id)
{
    if (id == LC_PUBLISH_REFUSED) {
        return;
    }
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        lc_app_outstanding_t *slot = &app->outstanding[i];
        if (slot->id != id) {
            continue;
        }
        /* A response stays pending in the journal; the next service sends it again. */
        lose(app, slot);
        memset(slot, 0, sizeof(*slot));
        return;
    }
}

/* An acknowledgement that never came, for example one lost by a full queue. */
static void expire_outstanding(lc_app_t *app, int64_t now_ms)
{
    for (size_t i = 0; i < LC_APP_OUTSTANDING_MAX; ++i) {
        lc_app_outstanding_t *slot = &app->outstanding[i];
        if (slot->id != LC_PUBLISH_REFUSED && now_ms - slot->sent_at_ms >= LC_APP_PUBLISH_TIMEOUT_MS) {
            lose(app, slot);
            memset(slot, 0, sizeof(*slot));
        }
    }
}

void lc_app_tick(lc_app_t *app)
{
    int64_t now_ms = app->ports.clock.now_ms(app->ports.clock.ctx);

    expire_outstanding(app, now_ms);
    lc_app_send_heartbeat(app, now_ms);
    if (now_ms < app->next_poll_ms) {
        return;
    }
    app->next_poll_ms = now_ms + LC_POLL_INTERVAL_MS;
    lc_app_service(app, now_ms);
}

void lc_app_begin_closing(lc_app_t *app)
{
    app->closing = true;
}

const lc_config_t *lc_app_config(const lc_app_t *app)
{
    return &app->config;
}
