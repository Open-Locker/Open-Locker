#include "lc_usb_console/setup_json.h"

#include <stddef.h>
#include <string.h>

#include "cJSON.h"
#include "lc_domain/text.h"

#define NESTING_MAX 4

typedef struct {
    const char *key;
    size_t offset;
    size_t capacity;
} field_t;

#define FIELD(name, member)                                                                  \
    {name, offsetof(lc_setup_request_t, member), sizeof(((lc_setup_request_t *)0)->member)}

static const field_t FIELDS[] = {
    FIELD("ssid", ssid),
    FIELD("wifi_password", wifi_password),
    FIELD("broker", broker_url),
    FIELD("bootstrap_user", bootstrap_user),
    FIELD("bootstrap_password", bootstrap_password),
    FIELD("token", token),
};

/* A \u0000 escape would end a string early, so it is refused outright. */
static bool has_nul_escape(const char *json)
{
    for (const char *c = json; *c != '\0'; ++c) {
        if (c[0] == '\\' && c[1] == 'u' && c[2] == '0' && c[3] == '0' && c[4] == '0' &&
            c[5] == '0') {
            return true;
        }
    }
    return false;
}

static const field_t *field_named(const char *key)
{
    for (size_t i = 0; i < sizeof(FIELDS) / sizeof(FIELDS[0]); ++i) {
        if (strcmp(FIELDS[i].key, key) == 0) {
            return &FIELDS[i];
        }
    }
    return NULL;
}

bool lc_console_parse_setup(const char *json, lc_setup_request_t *out)
{
    memset(out, 0, sizeof(*out));
    if (has_nul_escape(json)) {
        return false;
    }
    /* The line is short and nesting is not needed: anything deep is not ours. */
    int depth = 0;
    for (const char *c = json; *c != '\0'; ++c) {
        if ((*c == '{' || *c == '[') && ++depth > NESTING_MAX) {
            return false;
        }
    }

    cJSON *root = cJSON_Parse(json);
    bool valid = cJSON_IsObject(root);
    for (const cJSON *member = valid ? root->child : NULL; member != NULL && valid;
         member = member->next) {
        const field_t *field = field_named(member->string);
        valid = field != NULL && cJSON_IsString(member) &&
                lc_text_copy((char *)out + field->offset, field->capacity, member->valuestring);
        /* A key given twice would leave it open which value counts. */
        for (const cJSON *other = member->next; valid && other != NULL; other = other->next) {
            valid = strcmp(member->string, other->string) != 0;
        }
    }
    cJSON_Delete(root);
    if (!valid) {
        /* Not even a partial copy of what was refused stays behind. */
        memset(out, 0, sizeof(*out));
    }
    return valid;
}
