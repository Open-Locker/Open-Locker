#include <string.h>

#include "lc_domain/text.h"
#include "lc_mqtt_codec/internal.h"

static bool is_hex_digit(char c)
{
    return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}

/* 8-4-4-4-12 hexadecimal digits. */
static bool is_uuid(const char *text)
{
    if (strlen(text) != LC_UUID_SIZE - 1) {
        return false;
    }
    for (size_t i = 0; i < LC_UUID_SIZE - 1; ++i) {
        bool dash = i == 8 || i == 13 || i == 18 || i == 23;
        if (dash ? text[i] != '-' : !is_hex_digit(text[i])) {
            return false;
        }
    }
    return true;
}

static bool credential(const cJSON *data, const char *key, char *out)
{
    const char *value = lc_codec_get_string(data, key);
    return value != NULL && value[0] != '\0' &&
           lc_text_copy(out, LC_CREDENTIAL_MAX + 1, value);
}

bool lc_mqtt_decode_provisioning_reply(const char *payload, size_t length,
                                       lc_provisioning_reply_t *out)
{
    memset(out, 0, sizeof(*out));
    cJSON *root = lc_codec_parse_object(payload, length);
    if (root == NULL) {
        return false;
    }

    const char *message_id = lc_codec_get_string(root, "message_id");
    const char *status = lc_codec_get_string(root, "status");
    const char *timestamp = lc_codec_get_string(root, "timestamp");
    bool valid = message_id != NULL && lc_codec_is_identifier(message_id, LC_ID_MAX) &&
                 status != NULL && timestamp != NULL && lc_codec_is_timestamp(timestamp);

    if (valid && strcmp(status, "success") == 0) {
        const cJSON *data = cJSON_GetObjectItemCaseSensitive(root, "data");
        const char *locker_uuid = cJSON_IsObject(data) ? lc_codec_get_string(data, "locker_uuid")
                                                       : NULL;
        valid = locker_uuid != NULL && is_uuid(locker_uuid) &&
                credential(data, "mqtt_user", out->mqtt_user) &&
                credential(data, "mqtt_password", out->mqtt_password);
        if (valid) {
            (void)lc_text_copy(out->locker_uuid, sizeof(out->locker_uuid), locker_uuid);
            out->success = true;
        }
    } else if (valid) {
        valid = strcmp(status, "error") == 0;
    }

    cJSON_Delete(root);
    if (!valid) {
        memset(out, 0, sizeof(*out));
    }
    return valid;
}

size_t lc_mqtt_encode_registration(const char *client_id, const lc_mqtt_envelope_t *envelope,
                                   char *out, size_t capacity)
{
    lc_json_writer_t writer;

    lc_json_begin(&writer, out, capacity);
    lc_json_raw(&writer, "{");
    lc_json_key(&writer, "message_id", true);
    lc_json_string(&writer, envelope->message_id);
    lc_json_key(&writer, "client_id", false);
    lc_json_string(&writer, client_id);
    lc_json_key(&writer, "timestamp", false);
    lc_json_string(&writer, envelope->timestamp);
    lc_json_raw(&writer, "}");
    return lc_json_finish(&writer);
}

size_t lc_mqtt_encode_connection_offline(lc_offline_reason_t reason,
                                         const lc_mqtt_envelope_t *envelope, char *out,
                                         size_t capacity)
{
    lc_json_writer_t writer;

    lc_codec_begin_message(&writer, out, capacity, envelope);
    lc_json_key(&writer, "status", false);
    lc_json_string(&writer, "offline");
    lc_json_key(&writer, "reason", false);
    lc_json_string(&writer, reason == LC_OFFLINE_LAST_WILL ? "mqtt_last_will" : "shutdown");
    lc_json_raw(&writer, "}");
    return lc_json_finish(&writer);
}
