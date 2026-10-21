#include "lc_softap_portal/ol_config.h"

#include <string.h>

typedef struct {
    uint8_t tag;
    size_t offset;
    size_t capacity;
} item_t;

#define ITEM(tag, member) \
    {tag, offsetof(lc_setup_request_t, member), sizeof(((lc_setup_request_t *)0)->member)}

static const item_t ITEMS[] = {
    ITEM(1, broker_url),
    ITEM(2, bootstrap_user),
    ITEM(3, bootstrap_password),
    ITEM(4, token),
};
#define ITEM_COUNT (sizeof(ITEMS) / sizeof(ITEMS[0]))

void lc_collector_init(lc_collector_t *collector)
{
    memset(collector, 0, sizeof(*collector));
}

void lc_collector_clear(lc_collector_t *collector)
{
    /* The volatile pointer keeps the compiler from dropping the wipe of secrets. */
    volatile uint8_t *bytes = (volatile uint8_t *)collector;
    for (size_t i = 0; i < sizeof(*collector); ++i) {
        bytes[i] = 0;
    }
}

/* A string of `length` bytes that fits `capacity` with its terminator and holds no NUL. */
static bool store_field(char *field, size_t capacity, const uint8_t *bytes, size_t length)
{
    if (length >= capacity || (length > 0 && memchr(bytes, '\0', length) != NULL)) {
        return false;
    }
    memcpy(field, bytes, length);
    field[length] = '\0';
    return true;
}

bool lc_collector_wifi(lc_collector_t *collector, const uint8_t *ssid, size_t ssid_length,
                       const uint8_t *password, size_t password_length)
{
    lc_setup_request_t *request = &collector->request;
    /* Zeroed: the whole buffer is copied, and nothing old may ride along after the NUL. */
    char ssid_copy[sizeof(request->ssid)] = {0};
    char password_copy[sizeof(request->wifi_password)] = {0};

    collector->have_wifi = false;
    bool fits = store_field(ssid_copy, sizeof(ssid_copy), ssid, ssid_length) &&
                store_field(password_copy, sizeof(password_copy), password, password_length);
    if (fits) {
        memcpy(request->ssid, ssid_copy, sizeof(ssid_copy));
        memcpy(request->wifi_password, password_copy, sizeof(password_copy));
        collector->have_wifi = true;
    } else {
        memset(request->ssid, 0, sizeof(request->ssid));
        memset(request->wifi_password, 0, sizeof(request->wifi_password));
    }
    memset(ssid_copy, 0, sizeof(ssid_copy));
    memset(password_copy, 0, sizeof(password_copy));
    return fits;
}

static const item_t *item_for(uint8_t tag)
{
    for (size_t i = 0; i < ITEM_COUNT; ++i) {
        if (ITEMS[i].tag == tag) {
            return &ITEMS[i];
        }
    }
    return NULL;
}

static void wipe_config_fields(lc_setup_request_t *request)
{
    memset(request->broker_url, 0, sizeof(request->broker_url));
    memset(request->bootstrap_user, 0, sizeof(request->bootstrap_user));
    memset(request->bootstrap_password, 0, sizeof(request->bootstrap_password));
    memset(request->token, 0, sizeof(request->token));
}

bool lc_collector_ol_config(lc_collector_t *collector, const uint8_t *payload, size_t length)
{
    lc_setup_request_t *request = &collector->request;
    bool seen[ITEM_COUNT] = {false};
    bool valid = length >= 1 && length <= LC_OL_CONFIG_MAX && payload[0] == LC_OL_CONFIG_VERSION;
    size_t at = 1;

    collector->have_config = false;
    wipe_config_fields(request);
    while (valid && at < length) {
        if (length - at < 2) {
            valid = false;
            break;
        }
        const item_t *item = item_for(payload[at]);
        size_t value_length = payload[at + 1];
        at += 2;
        size_t index = item == NULL ? 0 : (size_t)(item - ITEMS);
        valid = item != NULL && !seen[index] && value_length > 0 && value_length <= length - at &&
                store_field((char *)request + item->offset, item->capacity, payload + at,
                            value_length);
        if (valid) {
            seen[index] = true;
            at += value_length;
        }
    }
    for (size_t i = 0; valid && i < ITEM_COUNT; ++i) {
        valid = seen[i];
    }

    if (valid) {
        collector->have_config = true;
    } else {
        /* Not even a partial copy of what was refused stays behind. */
        wipe_config_fields(request);
    }
    return valid;
}

bool lc_collector_ready(const lc_collector_t *collector)
{
    return collector->have_wifi && collector->have_config;
}

bool lc_collector_take(lc_collector_t *collector, lc_setup_request_t *out)
{
    if (!lc_collector_ready(collector)) {
        memset(out, 0, sizeof(*out));
        return false;
    }
    *out = collector->request;
    lc_collector_clear(collector);
    return true;
}

size_t lc_ol_config_reply(bool accepted, uint8_t out[1])
{
    out[0] = accepted ? LC_OL_CONFIG_REPLY_OK : LC_OL_CONFIG_REPLY_REFUSED;
    return 1;
}
