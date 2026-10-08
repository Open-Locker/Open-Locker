#include "locker_core.h"
#include <limits.h>
#include <stdlib.h>
#include <string.h>
#include <ctype.h>

static bool unique_keys(const cJSON *value, unsigned depth)
{
    if (depth > 16) return false;
    for (const cJSON *item = value->child; item; item = item->next) {
        if (cJSON_IsObject(value)) {
            for (const cJSON *other = item->next; other; other = other->next)
                if (!strcmp(item->string, other->string)) return false;
        }
        if (!unique_keys(item, depth + 1)) return false;
    }
    return true;
}
cJSON *lc_parse_json(const char *text)
{
    if (!text || strlen(text) > LC_PAYLOAD_MAX) return NULL;
    for (const char *p = text; *p; ++p) {
        if (!strncmp(p, "\\u0000", 6)) {
            size_t preceding = 0;
            for (const char *q = p; q > text && q[-1] == '\\'; --q) ++preceding;
            if (!(preceding % 2)) return NULL;
        }
    }
    cJSON *json = cJSON_ParseWithOpts(text, NULL, true);
    if (json && !unique_keys(json, 0)) { cJSON_Delete(json); return NULL; }
    return json;
}
static int digits(const char *p, size_t n)
{
    int result = 0;
    for (size_t i = 0; i < n; ++i) {
        if (p[i] < '0' || p[i] > '9') return -1;
        result = result * 10 + p[i] - '0';
    }
    return result;
}
bool lc_timestamp(const char *s)
{
    size_t length = strlen(s);
    if (length < 20 || s[4] != '-' || s[7] != '-' || (s[10] != 'T' && s[10] != 't') ||
        s[13] != ':' || s[16] != ':') return false;
    int year = digits(s, 4), month = digits(s + 5, 2), day = digits(s + 8, 2);
    int hour = digits(s + 11, 2), minute = digits(s + 14, 2), second = digits(s + 17, 2);
    if (year < 0 || month < 1 || month > 12 || day < 1 || hour < 0 || hour > 23 ||
        minute < 0 || minute > 59 || second < 0 || second > 59) return false;
    static const unsigned days[] = {31,28,31,30,31,30,31,31,30,31,30,31};
    unsigned limit = days[month - 1] + (month == 2 && year % 4 == 0 && (year % 100 != 0 || year % 400 == 0));
    if ((unsigned)day > limit) return false;
    size_t i = 19;
    if (s[i] == '.') { ++i; size_t start = i; while (isdigit((unsigned char)s[i])) ++i; if (i == start) return false; }
    if ((s[i] == 'Z' || s[i] == 'z') && s[i + 1] == 0) return true;
    if ((s[i] != '+' && s[i] != '-') || strlen(s + i) != 6 || s[i + 3] != ':') return false;
    int zone_hour = digits(s + i + 1, 2), zone_minute = digits(s + i + 4, 2);
    return zone_hour >= 0 && zone_hour <= 23 && zone_minute >= 0 && zone_minute <= 59;
}

bool lc_wifi_password(const char *text)
{
    size_t length = strlen(text);
    if (!length || (length >= 8 && length <= 63)) return true;
    if (length != 64) return false;
    for (size_t i = 0; i < length; ++i) if (!isxdigit((unsigned char)text[i])) return false;
    return true;
}

bool lc_string(const cJSON *object, const char *key, char *out, size_t capacity)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(object, key);
    if (!cJSON_IsString(v) || !v->valuestring || !*v->valuestring || strlen(v->valuestring) >= capacity)
        return false;
    for (const unsigned char *p = (const unsigned char *)v->valuestring; *p; ++p)
        if (*p < 32) return false;
    memcpy(out, v->valuestring, strlen(v->valuestring) + 1);
    return true;
}

bool lc_integer(const cJSON *object, const char *key, int min, int max, int *out)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(object, key);
    if (!cJSON_IsNumber(v) || v->valuedouble < min || v->valuedouble > max ||
        v->valuedouble != (double)v->valueint) return false;
    *out = v->valueint;
    return true;
}

static int compare(const void *a, const void *b)
{
    const lc_mapping_t *x = a, *y = b;
    return (x->number > y->number) - (x->number < y->number);
}

cJSON *lc_config_json(const lc_config_t *c)
{
    cJSON *root = cJSON_CreateObject();
    if (!root) return NULL;
    cJSON_AddStringToObject(root, "adapter_type", "rs485_lock_board");
    cJSON_AddStringToObject(root, "feedback_type", c->opening_feedback ? "door_opening" : "door_closing");
    cJSON *items = cJSON_AddArrayToObject(root, "compartments");
    for (size_t i = 0; i < c->count; ++i) {
        cJSON *item = cJSON_CreateObject();
        cJSON_AddNumberToObject(item, "compartment_number", c->mappings[i].number);
        cJSON_AddNumberToObject(item, "slaveId", c->mappings[i].board);
        cJSON_AddNumberToObject(item, "address", c->mappings[i].address);
        cJSON_AddItemToArray(items, item);
    }
    return root;
}

bool lc_parse_config(const cJSON *data, lc_config_t *c, char **canonical)
{
    *canonical = NULL;
    memset(c, 0, sizeof(*c));
    char adapter[32], feedback[32];
    int interval;
    if (!lc_string(data, "adapter_type", adapter, sizeof(adapter)) || strcmp(adapter, "rs485_lock_board") ||
        !lc_string(data, "feedback_type", feedback, sizeof(feedback)) ||
        (strcmp(feedback, "door_opening") && strcmp(feedback, "door_closing")) ||
        !lc_string(data, "config_hash", c->hash, sizeof(c->hash)) || strlen(c->hash) != 64 ||
        !lc_integer(data, "heartbeat_interval_seconds", 1, INT_MAX, &interval)) return false;
    for (size_t i = 0; i < 64; ++i) {
        if (!isxdigit((unsigned char)c->hash[i])) return false;
        c->hash[i] = (char)tolower((unsigned char)c->hash[i]);
    }
    c->heartbeat_seconds = (unsigned)interval;
    c->opening_feedback = !strcmp(feedback, "door_opening");
    const cJSON *items = cJSON_GetObjectItemCaseSensitive(data, "compartments");
    if (!cJSON_IsArray(items) || cJSON_GetArraySize(items) > LC_COMPARTMENTS_MAX) return false;
    const cJSON *item;
    cJSON_ArrayForEach(item, items) {
        int number, board, address;
        if (!cJSON_IsObject(item) || cJSON_GetArraySize(item) != 3 ||
            !lc_integer(item, "compartment_number", 1, INT_MAX, &number) ||
            !lc_integer(item, "slaveId", 1, 31, &board) ||
            !lc_integer(item, "address", 0, 254, &address)) return false;
        /* V1 deliberately supports one physical panel per bus. */
        for (size_t j = 0; j < c->count; ++j) {
            if (c->mappings[j].number == number || c->mappings[j].board != board ||
                c->mappings[j].address == address) return false;
        }
        c->mappings[c->count++] = (lc_mapping_t){number, (uint8_t)board, (uint8_t)address};
    }
    qsort(c->mappings, c->count, sizeof(c->mappings[0]), compare);
    cJSON *root = lc_config_json(c);
    if (!root) return false;
    *canonical = cJSON_PrintUnformatted(root);
    cJSON_Delete(root);
    c->applied = *canonical != NULL;
    return c->applied;
}
