#include "locker_setup.h"
#include <string.h>
int lc_usb_feed(lc_usb_line_t *in, unsigned char byte)
{
    if (byte == '\r' || byte == '\n') {
        if (in->discard) { memset(in, 0, sizeof(*in)); return -1; }
        in->line[in->length] = 0;
        in->length = 0;
        return *in->line ? 1 : 0;
    }
    if (in->discard) return 0;
    if (byte == 0 || in->length == LC_SETUP_MAX) {
        memset(in->line, 0, sizeof(in->line)); in->length = 0; in->discard = true;
        return 0;
    }
    in->line[in->length++] = (char)byte;
    return 0;
}
bool lc_setup_parse(const char *text, bool enrolled, bool pending, lc_setup_t *out)
{
    memset(out, 0, sizeof(*out));
    if (!text || strlen(text) > LC_SETUP_MAX || (!enrolled && pending)) return false;
    cJSON *json = lc_parse_json(text);
    const cJSON *password = cJSON_GetObjectItemCaseSensitive(json, "wifi_password");
    bool ok = cJSON_IsObject(json) &&
        lc_string(json, "ssid", out->ssid, sizeof(out->ssid)) &&
        cJSON_IsString(password) && strlen(password->valuestring) < sizeof(out->wifi_password);
    if (ok) {
        strcpy(out->wifi_password, password->valuestring);
        ok = lc_wifi_password(out->wifi_password) &&
             lc_string(json, "broker", out->broker, sizeof(out->broker)) &&
             !strncmp(out->broker, "mqtts://", 8) && strlen(out->broker) > 8 &&
             !strchr(out->broker, '@');
    }
    if (!enrolled) ok = ok &&
        lc_string(json, "bootstrap_user", out->bootstrap_user, sizeof(out->bootstrap_user)) &&
        lc_string(json, "bootstrap_password", out->bootstrap_password, sizeof(out->bootstrap_password)) &&
        lc_string(json, "token", out->token, sizeof(out->token)) && !strpbrk(out->token, "/+#");
    cJSON_Delete(json);
    if (!ok) memset(out, 0, sizeof(*out));
    return ok;
}
