#include <stdio.h>
#include <string.h>

#include "lc_mqtt_codec/codec.h"

/*
 * A name that is safe to put inside a topic: no separator, no wildcard, and
 * nothing outside the characters tokens and UUIDs are made of.
 */
static bool is_topic_safe(const char *name)
{
    if (name[0] == '\0') {
        return false;
    }
    for (const char *c = name; *c != '\0'; ++c) {
        bool allowed = (*c >= '0' && *c <= '9') || (*c >= 'a' && *c <= 'z') ||
                       (*c >= 'A' && *c <= 'Z') || *c == '-' || *c == '_' || *c == '.' ||
                       *c == '~';
        if (!allowed) {
            return false;
        }
    }
    return true;
}

static bool format(char *out, size_t capacity, const char *pattern, const char *name)
{
    int written = snprintf(out, capacity, pattern, name);
    return written > 0 && (size_t)written < capacity;
}

bool lc_mqtt_topic(lc_topic_t topic, const char *locker_uuid, char *out, size_t capacity)
{
    static const char *const SUFFIX[] = {
        [LC_TOPIC_COMMAND] = "command",
        [LC_TOPIC_RESPONSE] = "response",
        [LC_TOPIC_EVENT] = "event",
        [LC_TOPIC_HEARTBEAT] = "state/heartbeat",
        [LC_TOPIC_SNAPSHOT] = "state/compartments",
        [LC_TOPIC_CONNECTION] = "state/connection",
    };

    if ((unsigned)topic >= sizeof(SUFFIX) / sizeof(SUFFIX[0]) || !is_topic_safe(locker_uuid)) {
        return false;
    }
    int written = snprintf(out, capacity, "locker/%s/%s", locker_uuid, SUFFIX[topic]);
    return written > 0 && (size_t)written < capacity;
}

bool lc_mqtt_registration_topic(const char *token, char *out, size_t capacity)
{
    return is_topic_safe(token) && format(out, capacity, "locker/register/%s", token);
}

bool lc_mqtt_provisioning_reply_topic(const char *client_id, char *out, size_t capacity)
{
    return is_topic_safe(client_id) &&
           format(out, capacity, "locker/provisioning/reply/%s", client_id);
}

bool lc_mqtt_is_retained(lc_topic_t topic)
{
    return topic == LC_TOPIC_SNAPSHOT;
}

lc_inbound_t lc_mqtt_classify(const char *topic, const char *locker_uuid, const char *client_id)
{
    char expected[LC_MQTT_TOPIC_MAX + 1];

    if (lc_mqtt_topic(LC_TOPIC_COMMAND, locker_uuid, expected, sizeof(expected)) &&
        strcmp(topic, expected) == 0) {
        return LC_INBOUND_COMMAND;
    }
    if (lc_mqtt_provisioning_reply_topic(client_id, expected, sizeof(expected)) &&
        strcmp(topic, expected) == 0) {
        return LC_INBOUND_PROVISIONING_REPLY;
    }
    return LC_INBOUND_OTHER;
}
