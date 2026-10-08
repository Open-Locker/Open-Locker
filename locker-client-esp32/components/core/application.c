#include "locker_core.h"
#include <stdlib.h>
#include <string.h>
#include <limits.h>
#include <stdio.h>

static cJSON *response(const char *action, const char *transaction, const char *error)
{
    cJSON *body = cJSON_CreateObject();
    if (!body) return NULL;
    cJSON_AddStringToObject(body, "action", action);
    cJSON_AddStringToObject(body, "transaction_id", transaction);
    cJSON_AddStringToObject(body, "result", error ? "error" : "success");
    if (error) {
        cJSON_AddStringToObject(body, "error_code", error);
        cJSON_AddStringToObject(body, "message", error);
    }
    return body;
}

static bool publish(lc_app_t *a, const char *suffix, cJSON *body, bool retain)
{
    bool ok = body && a->ports.publish(a->ports.context, suffix, body, retain);
    cJSON_Delete(body);
    return ok;
}

static void replay(lc_app_t *a, const lc_record_t *r)
{
    if (!lc_journal_pending(a->journal, r->transaction)) return;
    cJSON *body = cJSON_Parse(r->response);
    if (publish(a, "response", body, false)) lc_journal_delivered(a->journal, r->transaction);
}

static void complete(lc_app_t *a, const char *transaction, cJSON *body)
{
    char encoded[LC_RESPONSE_MAX];
    bool ready = body && cJSON_PrintPreallocated(body, encoded, sizeof(encoded), false);
    cJSON_Delete(body);
    if (!ready) {
        /* Heap exhaustion after UART transmission must still leave a durable
         * final outcome. Input strings exclude control bytes; escape quotes
         * and backslashes into bounded stack storage without allocating. */
        const lc_record_t *r = lc_journal_find(a->journal, transaction);
        if (!r) return;
        char tx[LC_ID_MAX * 2 + 1], action[64];
        char *outputs[] = {tx, action};
        const char *inputs[] = {r->transaction, r->action};
        for (size_t i = 0; i < 2; ++i) {
            char *out = outputs[i];
            for (const char *p = inputs[i]; *p; ++p) {
                if (*p == '"' || *p == '\\') *out++ = '\\';
                *out++ = *p;
            }
            *out = 0;
        }
        snprintf(encoded, sizeof(encoded), "{\"action\":\"%s\",\"transaction_id\":\"%s\",\"result\":\"error\",\"error_code\":\"UNKNOWN_ERROR\",\"message\":\"Command outcome could not be encoded.\"}", action, tx);
    }
    if (lc_journal_complete(a->journal, transaction, encoded))
        replay(a, lc_journal_find(a->journal, transaction));
    /* If the final record cannot commit, retain the claim. A restart returns
     * unknown outcome; it must never infer that the unlock can be sent again. */
}

void lc_app_init(lc_app_t *a, lc_app_ports_t ports, lc_journal_t *j,
                 const lc_config_t *config, int64_t now)
{
    memset(a, 0, sizeof(*a));
    a->ports = ports;
    a->journal = j;
    a->config = *config;
    a->started_at = now;
    a->force_snapshot = true;
    for (size_t i = 0; i < LC_COMPARTMENTS_MAX; ++i) a->fired_at[i] = -1;
}

void lc_app_recover(lc_app_t *a)
{
    for (size_t i = 0; i < a->journal->count; ++i) {
        const lc_record_t *r = &a->journal->records[i];
        if (!r->completed) {
            cJSON *body = response(r->action, r->transaction, "UNKNOWN_ERROR");
            if (body) cJSON_ReplaceItemInObjectCaseSensitive(body, "message", cJSON_CreateString("Command outcome is unknown after ESP32 restart."));
            complete(a, r->transaction, body);
        }
    }
}

void lc_app_flush(lc_app_t *a)
{
    for (size_t i = 0; i < a->journal->count; ++i) {
        const lc_record_t *r = &a->journal->records[i];
        if (r->completed && !r->delivered) replay(a, r);
    }
}

void lc_app_dispatch(lc_app_t *a, const char *json, int64_t now)
{
    cJSON *root = lc_parse_json(json);
    if (!root) return;
    char transaction[LC_ID_MAX + 1], message[LC_ID_MAX + 1], action[32], timestamp[40];
    if (!lc_string(root, "transaction_id", transaction, sizeof(transaction))) goto done;
    if (!lc_string(root, "action", action, sizeof(action))) strcpy(action, "unknown");
    if (!lc_string(root, "message_id", message, sizeof(message))) {
        publish(a, "response", response(action, transaction, "MISSING_MESSAGE_ID"), false);
        goto done;
    }
    const lc_record_t *prior = lc_journal_find(a->journal, transaction);
    if (prior) {
        if (!lc_journal_remember(a->journal, transaction, message)) goto done;
        if (prior->completed) replay(a, prior);
        goto done;
    }
    if (lc_journal_seen(a->journal, message)) goto done;
    if (a->closing || !a->journal->healthy) {
        publish(a, "response", response(action, transaction, a->closing ? "SHUTTING_DOWN" : "UNKNOWN_ERROR"), false);
        goto done;
    }
    const cJSON *data = cJSON_GetObjectItemCaseSensitive(root, "data");
    bool apply = !strcmp(action, "apply_config"), open = !strcmp(action, "open_compartment");
    int number = 0;
    const char *error = NULL;
    if (!apply && !open) error = "UNKNOWN_ACTION";
    else if (!cJSON_IsObject(data) || !lc_string(root, "timestamp", timestamp, sizeof(timestamp)) || !lc_timestamp(timestamp) ||
             (open && !lc_integer(data, "compartment_number", 1, INT_MAX, &number))) error = "INVALID_COMMAND";
    if (!lc_journal_claim(a->journal, transaction, message, action)) {
        publish(a, "response", response(action, transaction, "UNKNOWN_ERROR"), false);
        goto done;
    }
    cJSON *body = NULL;
    if (!error && apply) {
        lc_config_t next;
        char *canonical = NULL, hash[65];
        if (!lc_parse_config(data, &next, &canonical) ||
            !a->ports.hash(a->ports.context, canonical, hash) || strcmp(hash, next.hash)) error = "INVALID_CONFIG";
        else if (!a->ports.save_config(a->ports.context, &next)) error = "INVALID_CONFIG";
        else {
            a->config = next;
            memset(a->states, 0, sizeof(a->states));
            memset(a->last_known, 0, sizeof(a->last_known));
            memset(a->unknown_reads, 0, sizeof(a->unknown_reads));
            memset(a->detecting, 0, sizeof(a->detecting));
            for (size_t i = 0; i < LC_COMPARTMENTS_MAX; ++i) a->fired_at[i] = -1;
            a->force_snapshot = true;
            a->next_heartbeat = 0;
            body = response(action, transaction, NULL);
            if (body) cJSON_AddStringToObject(body, "applied_config_hash", next.hash);
        }
        free(canonical);
    } else if (!error && open) {
        if (!a->config.applied) error = "RUNTIME_CONFIG_NOT_APPLIED";
        else {
            size_t i;
            for (i = 0; i < a->config.count; ++i) if (a->config.mappings[i].number == number) break;
            if (i == a->config.count) error = "COMPARTMENT_NOT_FOUND";
            else {
                lc_mapping_t target = a->config.mappings[i];
                lc_unlock_t result = a->ports.unlock(a->ports.context, target.board, target.address);
                if (result != LC_NOT_SENT) {
                    a->fired_at[i] = now;
                    strcpy(a->detecting[i], transaction);
                }
                if (result != LC_ACKNOWLEDGED) error = "HARDWARE_ERROR";
            }
        }
    }
    if (!body) body = response(action, transaction, error);
    complete(a, transaction, body);
done:
    cJSON_Delete(root);
}

static void door_event(lc_app_t *a, size_t i, int64_t now, bool opened)
{
    cJSON *event = cJSON_CreateObject();
    cJSON_AddStringToObject(event, "event", opened ? "compartment_open_detected" : "compartment_open_failed");
    cJSON *data = cJSON_AddObjectToObject(event, "data");
    cJSON_AddNumberToObject(data, "compartment_number", a->config.mappings[i].number);
    cJSON_AddStringToObject(data, "transaction_id", a->detecting[i]);
    cJSON_AddStringToObject(data, "outcome", opened ? "opened" : "door_jammed");
    if (opened) cJSON_AddNumberToObject(data, "detection_ms", (double)(now - a->fired_at[i]));
    else cJSON_AddStringToObject(data, "error_code", "DOOR_JAMMED");
    if (publish(a, "event", event, false)) a->detecting[i][0] = 0;
}

void lc_app_poll(lc_app_t *a, int64_t now)
{
    if (a->closing) return;
    if (now >= a->next_heartbeat) {
        cJSON *heartbeat = cJSON_CreateObject();
        cJSON_AddNumberToObject(heartbeat, "uptime_seconds", (double)((now - a->started_at) / 1000));
        cJSON_AddBoolToObject(heartbeat, "modbus_connected", a->ports.connected(a->ports.context));
        unsigned interval = a->config.applied ? a->config.heartbeat_seconds : 15;
        if (publish(a, "state/heartbeat", heartbeat, false)) a->next_heartbeat = now + (int64_t)interval * 1000;
    }
    if (!a->config.applied) return;
    lc_door_t raw[255];
    for (size_t i = 0; i < 255; ++i) raw[i] = LC_UNKNOWN;
    if (a->config.count) a->ports.read_states(a->ports.context, a->config.mappings[0].board,
                                             a->config.opening_feedback, raw);
    bool changed = a->force_snapshot;
    for (size_t i = 0; i < a->config.count; ++i) {
        lc_door_t observed = raw[a->config.mappings[i].address], previous = a->states[i];
        lc_door_t previous_known = a->last_known[i];
        lc_door_t effective = observed;
        if (observed == LC_UNKNOWN) {
            if (++a->unknown_reads[i] < 3) effective = previous;
            else a->unknown_reads[i] = 3;
        } else { a->unknown_reads[i] = 0; a->last_known[i] = observed; }
        a->states[i] = effective;
        changed |= previous != effective;
        if (*a->detecting[i]) {
            if (observed == LC_OPEN) door_event(a, i, now, true);
            else if (now - a->fired_at[i] >= (int64_t)a->config.heartbeat_seconds * 1000)
                door_event(a, i, now, false);
        } else if (previous_known == LC_CLOSED && observed == LC_OPEN &&
                   (a->fired_at[i] < 0 || now - a->fired_at[i] > (int64_t)a->config.heartbeat_seconds * 1000)) {
            cJSON *event = cJSON_CreateObject();
            cJSON_AddStringToObject(event, "event", "compartment_uncommanded_open");
            cJSON *data = cJSON_AddObjectToObject(event, "data");
            cJSON_AddNumberToObject(data, "compartment_number", a->config.mappings[i].number);
            if (a->fired_at[i] >= 0) cJSON_AddNumberToObject(data, "milliseconds_since_last_relay_fire", (double)(now - a->fired_at[i]));
            publish(a, "event", event, false);
        }
    }
    if (changed) {
        cJSON *snapshot = cJSON_CreateObject();
        cJSON *items = cJSON_AddArrayToObject(snapshot, "compartments");
        if (!snapshot || !items) { cJSON_Delete(snapshot); a->force_snapshot = true; return; }
        for (size_t i = 0; i < a->config.count; ++i) {
            cJSON *item = cJSON_CreateObject();
            if (!item) { cJSON_Delete(snapshot); a->force_snapshot = true; return; }
            cJSON_AddNumberToObject(item, "compartment_number", a->config.mappings[i].number);
            cJSON_AddStringToObject(item, "door_state", a->states[i] == LC_OPEN ? "open" : a->states[i] == LC_CLOSED ? "closed" : "unknown");
            cJSON_AddItemToArray(items, item);
        }
        a->force_snapshot = !publish(a, "state/compartments", snapshot, true);
    }
}
