#include "locker_setup.h"
#include "locker_core.h"
#include <assert.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static uint8_t flash[LC_SLOT_SIZE * 200];
static lc_journal_t journal, recovered;
static int fail_write = -1, writes, unlocks, published;
static bool can_publish = true, can_save = true;
static bool exhaust_after_unlock;
static lc_unlock_t unlock_result = LC_ACKNOWLEDGED;
static lc_door_t observed = LC_CLOSED;
static char last_response[LC_RESPONSE_MAX];
static char *fixture, *canonical_fixture;
static lc_config_t stored_config;
static FILE *emitted;

static bool read_flash(void *context, size_t offset, void *out, size_t size)
{
    (void)context;
    if (offset + size > sizeof(flash)) return false;
    memcpy(out, flash + offset, size);
    return true;
}
static bool write_flash(void *context, size_t offset, const void *in, size_t size)
{
    (void)context;
    const uint8_t *source = in;
    if (offset + size > sizeof(flash)) return false;
    int write = writes++;
    size_t limit = write == fail_write ? size / 2 : size;
    for (size_t i = 0; i < limit; ++i) {
        if ((flash[offset + i] & source[i]) != source[i]) return false;
        flash[offset + i] &= source[i];
    }
    return write != fail_write;
}
static lc_journal_io_t io(size_t slots)
{
    return (lc_journal_io_t){NULL, slots * LC_SLOT_SIZE, read_flash, write_flash};
}
static void fresh(void)
{
    memset(flash, 0xff, sizeof(flash));
    writes = 0; fail_write = -1; unlocks = 0; published = 0;
    can_publish = can_save = true;
    unlock_result = LC_ACKNOWLEDGED;
    observed = LC_CLOSED;
    memset(&stored_config, 0, sizeof(stored_config));
    assert(lc_journal_open(&journal, io(200)));
}
static bool hash(void *context, const char *text, char result[65])
{
    (void)context;
    /* The Python harness independently computes SHA-256 from this exact
     * canonical text. ESP-IDF's PSA SHA-256 adapter is target-built separately. */
    assert(!strcmp(text, canonical_fixture));
    strcpy(result, "3306f9a74f407226b567536b577fa7dfd460a674850ce9d1818aabe94f55ab62");
    return true;
}
static bool save_config(void *context, const lc_config_t *config)
{
    (void)context;
    if (!can_save) return false;
    stored_config = *config;
    return true;
}
static void *no_memory(size_t n) { (void)n; return NULL; }
static lc_unlock_t unlock(void *context, uint8_t board, unsigned address)
{
    (void)context;
    assert(board == 1 && address == 0);
    /* A real durable claim is visible before the side effect. */
    assert(lc_journal_find(&journal, "open-1") || lc_journal_find(&journal, "uncertain-1"));
    ++unlocks;
    if (exhaust_after_unlock) {
        cJSON_Hooks hooks = {no_memory, free};
        cJSON_InitHooks(&hooks);
    }
    return unlock_result;
}
static bool read_states(void *context, uint8_t board, bool opening, lc_door_t states[255])
{
    (void)context; (void)opening;
    assert(board == 1);
    for (size_t i = 0; i < 255; ++i) states[i] = observed;
    return observed != LC_UNKNOWN;
}
static bool connected(void *context) { (void)context; return observed != LC_UNKNOWN; }
static bool publish(void *context, const char *suffix, cJSON *body, bool retain)
{
    (void)context;
    if (!can_publish) return false;
    assert(body);
    ++published;
    if (!strcmp(suffix, "response")) {
        char *text = cJSON_PrintUnformatted(body);
        assert(strlen(text) < sizeof(last_response));
        strcpy(last_response, text);
        free(text);
    }
    if (emitted) {
        cJSON *copy = cJSON_Duplicate(body, true);
        cJSON_AddStringToObject(copy, "message_id", "host-generated-message");
        cJSON_AddStringToObject(copy, "timestamp", "2026-10-08T12:00:00Z");
        char *text = cJSON_PrintUnformatted(copy);
        fprintf(emitted, "{\"suffix\":\"%s\",\"retain\":%s,\"body\":%s}\n", suffix, retain ? "true" : "false", text);
        free(text);
        cJSON_Delete(copy);
    }
    return true;
}
static lc_app_ports_t ports(void)
{
    return (lc_app_ports_t){NULL, hash, save_config, unlock, read_states, publish, connected};
}
static void init(lc_app_t *a)
{
    lc_config_t none = {0};
    lc_app_init(a, ports(), &journal, &none, 0);
}
static void command(lc_app_t *a, const char *transaction, const char *message, const char *action, int number, int64_t now)
{
    char text[768];
    snprintf(text, sizeof(text), "{\"message_id\":\"%s\",\"transaction_id\":\"%s\",\"action\":\"%s\",\"timestamp\":\"2026-10-08T12:00:00Z\",\"data\":{\"compartment_number\":%d}}", message, transaction, action, number);
    lc_app_dispatch(a, text, now);
}
static void protocol_tests(void)
{
    uint8_t out[5];
    assert(lc_encode(1, 0, true, out));
    assert(!memcmp(out, (uint8_t[]){0x8a,1,1,0x11,0x9b}, 5));
    assert(lc_encode(1, 0, false, out));
    assert(!memcmp(out, (uint8_t[]){0x80,1,0,0x33,0xb2}, 5));
    assert(!lc_encode(0, 0, true, out));
    assert(!lc_encode(32, 0, true, out));
    assert(!lc_encode(1, 255, true, out));
    assert(lc_encode(31, 254, true, out) && out[2] == 255);
    assert(lc_ack((uint8_t[]){0x8a,1,1,0,0x8a}, 5, 1, 0));
    assert(!lc_ack((uint8_t[]){0x8a,1,1,0,0}, 5, 1, 0));
    assert(!lc_ack((uint8_t[]){0x8a,1,1,0,0x8a}, 5, 2, 0));
    lc_door_t states[255];
    assert(lc_status((uint8_t[]){0x80,1,0,0,0,0x33,0xb2}, 7, 1, false, states));
    assert(states[0] == LC_OPEN && states[23] == LC_OPEN && states[24] == LC_UNKNOWN);
    assert(lc_status((uint8_t[]){0x80,1,0,0,0,0x33,0xb2}, 7, 1, true, states));
    assert(states[0] == LC_CLOSED);
    assert(lc_status((uint8_t[]){0x80,1,0,1,0x33,0xb3}, 6, 1, false, states));
    assert(states[0] == LC_CLOSED && states[8] == LC_OPEN);
    assert(!lc_status((uint8_t[]){0x80,1,0,0x33,0}, 5, 1, false, states));
    assert(states[0] == LC_UNKNOWN);
    lc_assembler_t a = {0};
    assert(!lc_assemble(&a, "{", 1, 0, 2));
    assert(lc_assemble(&a, "}", 1, 1, 2) && !strcmp(a.data, "{}"));
    assert(!lc_assemble(&a, "{", 1, 0, LC_PAYLOAD_MAX + 1));
    assert(!lc_assemble(&a, "{", 1, 0, 3));
    assert(!lc_assemble(&a, "}", 1, 2, 3));
    assert(!lc_assemble(&a, "\0", 1, 0, 1));
    puts("PASS protocol vectors, sparse feedback, polarity, malformed frames and fragmentation");
}
static void journal_tests(void)
{
    fresh();
    assert(lc_journal_claim(&journal, "t", "m", "open_compartment"));
    assert(!lc_journal_claim(&journal, "t", "m2", "open_compartment"));
    assert(!lc_journal_claim(&journal, "t2", "m", "open_compartment"));
    assert(lc_journal_complete(&journal, "t", "{\"result\":\"success\"}"));
    assert(lc_journal_delivered(&journal, "t"));
    assert(lc_journal_open(&recovered, io(200)) && recovered.count == 1);
    assert(recovered.records[0].completed && recovered.records[0].delivered);
    flash[16] ^= 1;
    assert(!lc_journal_open(&recovered, io(200)));
    /* Both halves of a claim, completion and delivery commit are cut. */
    for (int cut = 0; cut < 6; ++cut) {
        fresh(); fail_write = cut;
        bool claimed = lc_journal_claim(&journal, "t", "m", "open_compartment");
        bool completed = claimed && lc_journal_complete(&journal, "t", "{\"result\":\"success\"}");
        if (completed) lc_journal_delivered(&journal, "t");
        assert(!lc_journal_open(&recovered, io(200))); /* partial write -> fail closed */
        assert(!journal.healthy);
    }
    fresh();
    assert(lc_journal_open(&journal, io(2)));
    assert(!lc_journal_claim(&journal, "t", "m", "open_compartment"));
    assert(journal.next_slot == 0);
    fresh();
    for (size_t i = 0; i < LC_RECORDS_MAX; ++i) {
        char id[20]; snprintf(id, sizeof(id), "t%u", (unsigned)i);
        assert(lc_journal_claim(&journal, id, id, "open_compartment"));
        assert(lc_journal_complete(&journal, id, "{\"result\":\"success\"}"));
        assert(lc_journal_delivered(&journal, id));
    }
    assert(!lc_journal_claim(&journal, "full", "full", "open_compartment"));
    puts("PASS journal claims, CRC, six torn-write boundaries, reservations and capacity");
}
static void config_tests(void)
{
    assert(!lc_parse_json("{\"a\":1,\"a\":2}"));
    assert(!lc_parse_json("{\"a\":\"x\\u0000y\"}"));
    assert(!lc_timestamp("2026-02-30T00:00:00Z"));
    assert(!lc_timestamp("2026-10-08T99:00:00Z"));
    assert(lc_timestamp("2024-02-29T00:00:00.123+02:00"));
    assert(lc_timestamp("2026-10-08T00:00:00Z"));
    assert(lc_wifi_password("") && lc_wifi_password("eightchr"));
    assert(!lc_wifi_password("short"));
    assert(!lc_wifi_password("zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"));
    cJSON *root = cJSON_Parse(fixture);
    cJSON *data = cJSON_GetObjectItemCaseSensitive(root, "data");
    lc_config_t config;
    char *canonical = NULL;
    assert(lc_parse_config(data, &config, &canonical));
    assert(config.count == 2 && !strcmp(canonical, canonical_fixture));
    free(canonical);
    cJSON *items = cJSON_GetObjectItemCaseSensitive(data, "compartments");
    cJSON *first = cJSON_DetachItemFromArray(items, 0);
    cJSON_AddItemToArray(items, first);
    assert(lc_parse_config(data, &config, &canonical));
    assert(!strcmp(canonical, canonical_fixture)); free(canonical);
    cJSON *other = cJSON_GetArrayItem(items, 0);
    cJSON_SetNumberValue(cJSON_GetObjectItem(other, "slaveId"), 2);
    assert(!lc_parse_config(data, &config, &canonical));
    cJSON_SetNumberValue(cJSON_GetObjectItem(other, "slaveId"), 1);
    cJSON_SetNumberValue(cJSON_GetObjectItem(other, "address"), 0);
    assert(!lc_parse_config(data, &config, &canonical));
    cJSON_SetNumberValue(cJSON_GetObjectItem(other, "address"), 1.5);
    assert(!lc_parse_config(data, &config, &canonical));
    cJSON_Delete(root);
    puts("PASS canonical config, sorted mappings, duplicate targets and unsupported profiles");
}
static void app_tests(void)
{
    lc_app_t a;
    fresh(); init(&a);
    command(&a, "missing-config", "m1", "open_compartment", 1, 0);
    assert(!unlocks && strstr(last_response, "RUNTIME_CONFIG_NOT_APPLIED"));
    lc_app_dispatch(&a, fixture, 0);
    assert(a.config.applied && stored_config.applied && strstr(last_response, "applied_config_hash"));
    command(&a, "open-1", "m2", "open_compartment", 1, 100);
    assert(unlocks == 1 && strstr(last_response, "success"));
    command(&a, "open-1", "new-message", "open_compartment", 1, 200);
    assert(unlocks == 1);
    command(&a, "transport-duplicate", "new-message", "open_compartment", 1, 200);
    assert(unlocks == 1 && !lc_journal_find(&journal, "transport-duplicate"));
    can_publish = false;
    command(&a, "open-1", "lost-replay", "open_compartment", 1, 200);
    assert(!lc_journal_find(&journal, "open-1")->delivered);
    can_publish = true;
    lc_app_flush(&a);
    assert(lc_journal_find(&journal, "open-1")->delivered && unlocks == 1);
    command(&a, "different-transaction", "m2", "open_compartment", 1, 200);
    assert(unlocks == 1);
    /* Reboot reloads the durable transaction and only replays its response. */
    assert(lc_journal_open(&recovered, io(200)));
    lc_app_init(&a, ports(), &recovered, &stored_config, 0);
    assert(lc_journal_seen(&recovered, "m2") && lc_journal_seen(&recovered, "new-message"));
    command(&a, "open-1", "another-message", "open_compartment", 1, 1000);
    assert(unlocks == 1);
    fresh(); init(&a); lc_app_dispatch(&a, fixture, 0);
    unlock_result = LC_UNCERTAIN;
    command(&a, "uncertain-1", "m3", "open_compartment", 1, 100);
    assert(unlocks == 1 && strstr(last_response, "HARDWARE_ERROR"));
    command(&a, "uncertain-1", "m4", "open_compartment", 1, 200);
    assert(unlocks == 1);
    observed = LC_OPEN; lc_app_poll(&a, 600);
    assert(!*a.detecting[0]); /* sensor observation is separate from failed ACK */
    fresh(); init(&a);
    assert(lc_journal_claim(&journal, "interrupted", "interrupted-message", "open_compartment"));
    lc_app_recover(&a);
    assert(!unlocks && strstr(last_response, "UNKNOWN_ERROR"));
    can_publish = false;
    command(&a, "pending-response", "pending-message", "unknown_action", 1, 0);
    assert(lc_journal_find(&journal, "pending-response")->completed);
    assert(!lc_journal_find(&journal, "pending-response")->delivered);
    can_publish = true; lc_app_flush(&a);
    assert(lc_journal_find(&journal, "pending-response")->delivered && !unlocks);
    a.closing = true;
    command(&a, "closing", "closing-message", "open_compartment", 1, 0);
    assert(!lc_journal_find(&journal, "closing") && strstr(last_response, "SHUTTING_DOWN"));
    fresh(); init(&a); can_save = false;
    lc_app_dispatch(&a, fixture, 0);
    assert(!a.config.applied && strstr(last_response, "INVALID_CONFIG"));
    fresh(); init(&a); lc_app_dispatch(&a, fixture, 0);
    exhaust_after_unlock = true;
    command(&a, "open-1", "oom-message", "open_compartment", 1, 0);
    cJSON_InitHooks(NULL); exhaust_after_unlock = false;
    assert(unlocks == 1 && lc_journal_find(&journal, "open-1")->completed);
    lc_app_flush(&a);
    assert(strstr(last_response, "UNKNOWN_ERROR"));
    command(&a, "open-1", "oom-redelivery", "open_compartment", 1, 0);
    assert(unlocks == 1);
    puts("PASS application enrollment-independent commands, durable redelivery, uncertain unlock, restart, pending replies and shutdown");
}
static void state_tests(void)
{
    lc_app_t a;
    fresh(); init(&a); lc_app_dispatch(&a, fixture, 0);
    lc_app_poll(&a, 0);
    int before = published;
    lc_app_poll(&a, 500);
    assert(before == published); /* unchanged snapshots remain quiet */
    observed = LC_UNKNOWN;
    lc_app_poll(&a, 1000); assert(a.states[0] == LC_CLOSED);
    lc_app_poll(&a, 1500); assert(a.states[0] == LC_CLOSED);
    lc_app_poll(&a, 2000); assert(a.states[0] == LC_UNKNOWN);
    observed = LC_CLOSED; lc_app_poll(&a, 2500);
    observed = LC_OPEN; lc_app_poll(&a, 3000); /* uncommanded event */
    observed = LC_CLOSED; lc_app_poll(&a, 3500);
    command(&a, "open-1", "state-open", "open_compartment", 1, 4000);
    lc_app_poll(&a, 19500); /* jammed */
    assert(!*a.detecting[0]);
    puts("PASS change-only snapshots, unknown hysteresis, uncommanded opening and jam detection");
}
static char *file(const char *path)
{
    FILE *input = fopen(path, "rb"); assert(input);
    assert(fseek(input, 0, SEEK_END) == 0);
    long size = ftell(input); assert(size >= 0);
    rewind(input);
    char *text = calloc(1, (size_t)size + 1); assert(text);
    assert(fread(text, 1, (size_t)size, input) == (size_t)size);
    fclose(input); return text;
}
static void setup_tests(void)
{
    lc_setup_t settings;
    const char *valid = "{\"ssid\":\"bench\",\"wifi_password\":\"password\",\"broker\":\"mqtts://example.com:8883\",\"bootstrap_user\":\"setup\",\"bootstrap_password\":\"secret\",\"token\":\"one-time\"}";
    assert(lc_setup_parse(valid, false, false, &settings));
    assert(!strcmp(settings.ssid, "bench"));
    assert(!lc_setup_parse(valid, false, true, &settings));
    assert(!*settings.token);
    const char *network = "{\"ssid\":\"bench\",\"wifi_password\":\"\",\"broker\":\"mqtts://example.com\"}";
    assert(lc_setup_parse(network, true, false, &settings));
    assert(!*settings.token && !*settings.bootstrap_password);
    assert(!lc_setup_parse(network, false, false, &settings));
    assert(!lc_setup_parse("{\"ssid\":\"x\",\"ssid\":\"y\"}", true, false, &settings));
    assert(!lc_setup_parse("{\"ssid\":\"bench\",\"wifi_password\":\"short\",\"broker\":\"mqtts://example.com\"}", true, false, &settings));
    assert(!lc_setup_parse("{\"ssid\":\"bench\",\"wifi_password\":\"\",\"broker\":\"mqtt://example.com\"}", true, false, &settings));
    lc_usb_line_t line = {0};
    assert(!lc_usb_feed(&line, 's')); assert(!lc_usb_feed(&line, 'e'));
    assert(lc_usb_feed(&line, '\n') == 1 && !strcmp(line.line, "se"));
    assert(!lc_usb_feed(&line, '\n'));
    for (unsigned i = 0; i < LC_SETUP_MAX + 1; ++i) assert(!lc_usb_feed(&line, 'x'));
    assert(!lc_usb_feed(&line, 's'));
    assert(lc_usb_feed(&line, '\n') == -1);
    assert(!lc_usb_feed(&line, 0)); assert(!lc_usb_feed(&line, 's'));
    assert(lc_usb_feed(&line, '\r') == -1); assert(!lc_usb_feed(&line, '\n'));
    assert(!lc_usb_feed(&line, 's')); assert(lc_usb_feed(&line, '\n') == 1);
    puts("PASS USB setup validation, enrolled updates, pending rejection, fragmented lines, overflow and NUL recovery");
}
int main(int argc, char **argv)
{
    assert(argc == 4);
    fixture = file(argv[1]); canonical_fixture = file(argv[2]);
    emitted = fopen(argv[3], "wb"); assert(emitted);
    setup_tests(); protocol_tests(); journal_tests(); config_tests(); app_tests(); state_tests();
    fclose(emitted); free(fixture); free(canonical_fixture);
    puts("All native tests passed.");
    return 0;
}
