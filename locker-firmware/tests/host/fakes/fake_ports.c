#include "fake_ports.h"

#include <assert.h>
#include <stdio.h>
#include <string.h>

/* ---- Trace ---- */

#define TRACE_MAX 2048

static const char *trace[TRACE_MAX];
static size_t trace_count;

void fake_trace_reset(void)
{
    trace_count = 0;
}

void fake_trace(const char *what)
{
    assert(trace_count < TRACE_MAX);
    trace[trace_count++] = what;
}

int fake_trace_first(const char *what)
{
    for (size_t i = 0; i < trace_count; ++i) {
        if (strcmp(trace[i], what) == 0) {
            return (int)i;
        }
    }
    return -1;
}

unsigned fake_trace_count(const char *what)
{
    unsigned count = 0;
    for (size_t i = 0; i < trace_count; ++i) {
        if (strcmp(trace[i], what) == 0) {
            ++count;
        }
    }
    return count;
}

/* ---- Clock ---- */

static int64_t clock_now_ms(void *ctx)
{
    const fake_clock_t *clock = ctx;
    return clock->now_ms;
}

lc_clock_port_t fake_clock_port(fake_clock_t *clock)
{
    return (lc_clock_port_t){.ctx = clock, .now_ms = clock_now_ms};
}

/* ---- Locker bus ---- */

static bool bus_apply_profile(void *ctx, const lc_hardware_profile_t *profile)
{
    fake_bus_t *bus = ctx;
    fake_trace("bus.profile");
    ++bus->profile_count;
    bus->last_profile = *profile;
    return bus->apply_profile_ok;
}

static lc_unlock_observation_t bus_unlock(void *ctx, lc_target_t target)
{
    fake_bus_t *bus = ctx;
    fake_trace("bus.unlock");
    ++bus->unlock_count;
    bus->last_target = target;
    return (lc_unlock_observation_t){.result = bus->unlock_result, .observed = LC_DOOR_UNKNOWN};
}

static bool bus_read(void *ctx, uint8_t board, const uint8_t *addresses, size_t count,
                     lc_door_t *out)
{
    fake_bus_t *bus = ctx;
    (void)board;
    fake_trace("bus.read");
    ++bus->read_count;
    if (bus->during_read != NULL) {
        bus->during_read(bus->hook_ctx);
    }
    for (size_t i = 0; i < count; ++i) {
        out[i] = bus->read_ok ? bus->doors[addresses[i]] : LC_DOOR_UNKNOWN;
    }
    fake_trace("bus.read.end");
    return bus->read_ok;
}

static lc_bus_state_t bus_state(void *ctx)
{
    const fake_bus_t *bus = ctx;
    return bus->state;
}

void fake_bus_init(fake_bus_t *bus)
{
    memset(bus, 0, sizeof(*bus));
    for (size_t address = 0; address < FAKE_BUS_ADDRESSES; ++address) {
        bus->doors[address] = address < 24 ? LC_DOOR_CLOSED : LC_DOOR_UNKNOWN;
    }
    bus->read_ok = true;
    bus->unlock_result = LC_UNLOCK_ACKNOWLEDGED;
    bus->state = LC_BUS_CONNECTED;
    bus->apply_profile_ok = true;
}

lc_locker_bus_port_t fake_bus_port(fake_bus_t *bus)
{
    return (lc_locker_bus_port_t){
        .ctx = bus,
        .apply_profile = bus_apply_profile,
        .unlock_compartment = bus_unlock,
        .read_compartment_states = bus_read,
        .connection_state = bus_state,
    };
}

/* ---- Command journal ---- */

static lc_journal_entry_t *journal_entry(fake_journal_t *journal, const char *transaction_id)
{
    for (size_t i = 0; i < journal->count; ++i) {
        if (strcmp(journal->entries[i].transaction_id, transaction_id) == 0) {
            return &journal->entries[i];
        }
    }
    return NULL;
}

static void journal_add_message(fake_journal_t *journal, const char *message_id)
{
    assert(journal->message_count < FAKE_JOURNAL_MESSAGES);
    snprintf(journal->messages[journal->message_count++], LC_ID_MAX + 1, "%s", message_id);
}

static bool journal_healthy(void *ctx)
{
    const fake_journal_t *journal = ctx;
    return journal->healthy;
}

static bool journal_seen_message(void *ctx, const char *message_id)
{
    const fake_journal_t *journal = ctx;
    for (size_t i = 0; i < journal->message_count; ++i) {
        if (strcmp(journal->messages[i], message_id) == 0) {
            return true;
        }
    }
    return false;
}

static bool journal_find(void *ctx, const char *transaction_id, lc_journal_entry_t *out)
{
    const lc_journal_entry_t *entry = journal_entry(ctx, transaction_id);
    if (entry == NULL) {
        return false;
    }
    *out = *entry;
    return true;
}

static bool journal_claim(void *ctx, const char *transaction_id, const char *message_id,
                          const char *action_name)
{
    fake_journal_t *journal = ctx;
    fake_trace("journal.claim");
    if (!journal->healthy || journal->refuse_claim ||
        journal_entry(journal, transaction_id) != NULL) {
        return false;
    }
    assert(journal->count < FAKE_JOURNAL_ENTRIES);
    lc_journal_entry_t *entry = &journal->entries[journal->count++];
    memset(entry, 0, sizeof(*entry));
    snprintf(entry->transaction_id, sizeof(entry->transaction_id), "%s", transaction_id);
    snprintf(entry->action_name, sizeof(entry->action_name), "%s", action_name);
    journal_add_message(journal, message_id);
    ++journal->claim_count;
    return true;
}

static bool journal_remember_message(void *ctx, const char *transaction_id,
                                     const char *message_id)
{
    fake_journal_t *journal = ctx;
    if (journal_entry(journal, transaction_id) == NULL) {
        return false;
    }
    journal_add_message(journal, message_id);
    return true;
}

static bool journal_complete(void *ctx, const lc_command_result_t *result)
{
    fake_journal_t *journal = ctx;
    fake_trace("journal.complete");
    lc_journal_entry_t *entry = journal_entry(journal, result->transaction_id);
    if (entry == NULL || entry->completed || journal->fail_complete) {
        return false;
    }
    entry->completed = true;
    entry->result = *result;
    ++journal->complete_count;
    return true;
}

static bool journal_mark_delivered(void *ctx, const char *transaction_id)
{
    fake_journal_t *journal = ctx;
    fake_trace("journal.delivered");
    lc_journal_entry_t *entry = journal_entry(journal, transaction_id);
    if (entry == NULL || !entry->completed) {
        return false;
    }
    entry->delivered = true;
    ++journal->delivered_count;
    return true;
}

static size_t journal_count(void *ctx)
{
    const fake_journal_t *journal = ctx;
    return journal->count;
}

static bool journal_at(void *ctx, size_t index, lc_journal_entry_t *out)
{
    const fake_journal_t *journal = ctx;
    if (index >= journal->count) {
        return false;
    }
    *out = journal->entries[index];
    return true;
}

void fake_journal_init(fake_journal_t *journal)
{
    memset(journal, 0, sizeof(*journal));
    journal->healthy = true;
}

lc_command_journal_port_t fake_journal_port(fake_journal_t *journal)
{
    return (lc_command_journal_port_t){
        .ctx = journal,
        .healthy = journal_healthy,
        .seen_message = journal_seen_message,
        .find = journal_find,
        .claim = journal_claim,
        .remember_message = journal_remember_message,
        .complete = journal_complete,
        .mark_delivered = journal_mark_delivered,
        .count = journal_count,
        .at = journal_at,
    };
}

const lc_journal_entry_t *fake_journal_entry(const fake_journal_t *journal,
                                             const char *transaction_id)
{
    for (size_t i = 0; i < journal->count; ++i) {
        if (strcmp(journal->entries[i].transaction_id, transaction_id) == 0) {
            return &journal->entries[i];
        }
    }
    return NULL;
}

/* ---- Outbound ---- */

static const char *const PUBLISH_TRACE[FAKE_PUBLISH_KINDS] = {
    "outbound.response",   "outbound.heartbeat",   "outbound.snapshot",
    "outbound.detection",  "outbound.uncommanded", "outbound.offline",
};

/* Returns the id of an accepted publish, or LC_PUBLISH_REFUSED. */
static lc_publish_id_t outbound_publish(fake_outbound_t *outbound, fake_publish_kind_t kind)
{
    fake_trace(PUBLISH_TRACE[kind]);
    ++outbound->attempts[kind];
    if (outbound->refuse[kind] > 0) {
        --outbound->refuse[kind];
        return LC_PUBLISH_REFUSED;
    }
    assert(outbound->id_count < FAKE_OUTBOUND_IDS);
    lc_publish_id_t id = (lc_publish_id_t)(outbound->id_count + 1);
    outbound->ids[outbound->id_count++] = id;
    outbound->last_id[kind] = id;
    ++outbound->accepted[kind];
    return id;
}

static lc_publish_id_t outbound_response(void *ctx, const lc_command_result_t *result)
{
    fake_outbound_t *outbound = ctx;
    lc_publish_id_t id = outbound_publish(outbound, FAKE_RESPONSE);
    if (id != LC_PUBLISH_REFUSED) {
        assert(outbound->response_count < FAKE_OUTBOUND_RESPONSES);
        outbound->responses[outbound->response_count++] = *result;
    }
    return id;
}

static lc_publish_id_t outbound_heartbeat(void *ctx, uint32_t uptime_seconds, bool bus_connected)
{
    fake_outbound_t *outbound = ctx;
    lc_publish_id_t id = outbound_publish(outbound, FAKE_HEARTBEAT);
    if (id != LC_PUBLISH_REFUSED) {
        outbound->heartbeat_uptime_seconds = uptime_seconds;
        outbound->heartbeat_bus_connected = bus_connected;
    }
    return id;
}

static lc_publish_id_t outbound_snapshot(void *ctx, const lc_compartment_state_t *states,
                                         size_t count)
{
    fake_outbound_t *outbound = ctx;
    lc_publish_id_t id = outbound_publish(outbound, FAKE_SNAPSHOT);
    if (id != LC_PUBLISH_REFUSED) {
        assert(count <= LC_COMPARTMENTS_MAX);
        memcpy(outbound->snapshot, states, count * sizeof(states[0]));
        outbound->snapshot_count = count;
    }
    return id;
}

static lc_publish_id_t outbound_open_detection(void *ctx, const lc_open_detection_t *event)
{
    fake_outbound_t *outbound = ctx;
    lc_publish_id_t id = outbound_publish(outbound, FAKE_OPEN_DETECTION);
    if (id != LC_PUBLISH_REFUSED) {
        assert(outbound->detection_count < FAKE_OUTBOUND_EVENTS);
        outbound->detections[outbound->detection_count++] = *event;
    }
    return id;
}

static lc_publish_id_t outbound_uncommanded_open(void *ctx, const lc_uncommanded_open_t *event)
{
    fake_outbound_t *outbound = ctx;
    lc_publish_id_t id = outbound_publish(outbound, FAKE_UNCOMMANDED_OPEN);
    if (id != LC_PUBLISH_REFUSED) {
        assert(outbound->uncommanded_count < FAKE_OUTBOUND_EVENTS);
        outbound->uncommanded[outbound->uncommanded_count++] = *event;
    }
    return id;
}

static lc_publish_id_t outbound_offline(void *ctx, lc_offline_reason_t reason)
{
    fake_outbound_t *outbound = ctx;
    lc_publish_id_t id = outbound_publish(outbound, FAKE_OFFLINE);
    if (id != LC_PUBLISH_REFUSED) {
        outbound->offline_reason = reason;
    }
    return id;
}

void fake_outbound_init(fake_outbound_t *outbound)
{
    memset(outbound, 0, sizeof(*outbound));
}

lc_outbound_port_t fake_outbound_port(fake_outbound_t *outbound)
{
    return (lc_outbound_port_t){
        .ctx = outbound,
        .command_response = outbound_response,
        .heartbeat = outbound_heartbeat,
        .compartment_snapshot = outbound_snapshot,
        .open_detection = outbound_open_detection,
        .uncommanded_open = outbound_uncommanded_open,
        .connection_offline = outbound_offline,
    };
}

unsigned fake_outbound_attempts(const fake_outbound_t *outbound)
{
    unsigned total = 0;
    for (size_t kind = 0; kind < FAKE_PUBLISH_KINDS; ++kind) {
        total += outbound->attempts[kind];
    }
    return total;
}

const lc_command_result_t *fake_outbound_last_response(const fake_outbound_t *outbound)
{
    return outbound->response_count == 0 ? NULL
                                         : &outbound->responses[outbound->response_count - 1];
}

int fake_outbound_snapshot_state(const fake_outbound_t *outbound, uint16_t compartment_number)
{
    for (size_t i = 0; i < outbound->snapshot_count; ++i) {
        if (outbound->snapshot[i].compartment_number == compartment_number) {
            return (int)outbound->snapshot[i].state;
        }
    }
    return -1;
}

/* ---- Stores ---- */

static bool config_load(void *ctx, lc_config_t *out)
{
    const fake_config_store_t *store = ctx;
    if (store->has) {
        *out = store->config;
    }
    return store->has;
}

static bool config_save(void *ctx, const lc_config_t *config)
{
    fake_config_store_t *store = ctx;
    fake_trace("config.save");
    if (!store->save_ok) {
        return false;
    }
    store->config = *config;
    store->has = true;
    ++store->save_count;
    return true;
}

static bool config_clear(void *ctx)
{
    fake_config_store_t *store = ctx;
    fake_trace("config.clear");
    store->has = false;
    ++store->clear_count;
    return true;
}

void fake_config_store_init(fake_config_store_t *store)
{
    memset(store, 0, sizeof(*store));
    store->save_ok = true;
}

lc_config_store_port_t fake_config_store_port(fake_config_store_t *store)
{
    return (lc_config_store_port_t){
        .ctx = store, .load = config_load, .save = config_save, .clear = config_clear};
}

static bool identity_load(void *ctx, lc_identity_t *out)
{
    const fake_identity_store_t *store = ctx;
    if (store->has) {
        *out = store->identity;
    }
    return store->has;
}

static bool identity_save(void *ctx, const lc_identity_t *identity)
{
    fake_identity_store_t *store = ctx;
    fake_trace("identity.save");
    if (!store->save_ok) {
        return false;
    }
    store->identity = *identity;
    store->has = true;
    ++store->save_count;
    return true;
}

void fake_identity_store_init(fake_identity_store_t *store)
{
    memset(store, 0, sizeof(*store));
    store->save_ok = true;
}

lc_identity_store_port_t fake_identity_store_port(fake_identity_store_t *store)
{
    return (lc_identity_store_port_t){.ctx = store, .load = identity_load, .save = identity_save};
}

static bool settings_load(void *ctx, lc_network_settings_t *out)
{
    const fake_settings_store_t *store = ctx;
    if (store->has) {
        *out = store->settings;
    }
    return store->has;
}

static bool settings_save(void *ctx, const lc_network_settings_t *settings)
{
    fake_settings_store_t *store = ctx;
    fake_trace("settings.save");
    if (!store->save_ok) {
        return false;
    }
    store->settings = *settings;
    store->has = true;
    ++store->save_count;
    return true;
}

static bool settings_clear(void *ctx)
{
    fake_settings_store_t *store = ctx;
    fake_trace("settings.clear");
    store->has = false;
    ++store->clear_count;
    return true;
}

void fake_settings_store_init(fake_settings_store_t *store)
{
    memset(store, 0, sizeof(*store));
    store->save_ok = true;
}

lc_settings_store_port_t fake_settings_store_port(fake_settings_store_t *store)
{
    return (lc_settings_store_port_t){
        .ctx = store, .load = settings_load, .save = settings_save, .clear = settings_clear};
}

/* ---- Hasher ---- */

static const uint32_t SHA256_K[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4,
    0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe,
    0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f,
    0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
    0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc,
    0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
    0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116,
    0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7,
    0xc67178f2,
};

static uint32_t rotate_right(uint32_t value, unsigned bits)
{
    return (value >> bits) | (value << (32 - bits));
}

static void sha256_block(uint32_t state[8], const uint8_t block[64])
{
    uint32_t w[64];
    for (size_t i = 0; i < 16; ++i) {
        w[i] = ((uint32_t)block[i * 4] << 24) | ((uint32_t)block[i * 4 + 1] << 16) |
               ((uint32_t)block[i * 4 + 2] << 8) | (uint32_t)block[i * 4 + 3];
    }
    for (size_t i = 16; i < 64; ++i) {
        uint32_t s0 = rotate_right(w[i - 15], 7) ^ rotate_right(w[i - 15], 18) ^ (w[i - 15] >> 3);
        uint32_t s1 = rotate_right(w[i - 2], 17) ^ rotate_right(w[i - 2], 19) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16] + s0 + w[i - 7] + s1;
    }

    uint32_t v[8];
    memcpy(v, state, sizeof(v));
    for (size_t i = 0; i < 64; ++i) {
        uint32_t s1 = rotate_right(v[4], 6) ^ rotate_right(v[4], 11) ^ rotate_right(v[4], 25);
        uint32_t choice = (v[4] & v[5]) ^ (~v[4] & v[6]);
        uint32_t t1 = v[7] + s1 + choice + SHA256_K[i] + w[i];
        uint32_t s0 = rotate_right(v[0], 2) ^ rotate_right(v[0], 13) ^ rotate_right(v[0], 22);
        uint32_t majority = (v[0] & v[1]) ^ (v[0] & v[2]) ^ (v[1] & v[2]);
        uint32_t t2 = s0 + majority;
        v[7] = v[6];
        v[6] = v[5];
        v[5] = v[4];
        v[4] = v[3] + t1;
        v[3] = v[2];
        v[2] = v[1];
        v[1] = v[0];
        v[0] = t1 + t2;
    }
    for (size_t i = 0; i < 8; ++i) {
        state[i] += v[i];
    }
}

void fake_sha256_hex(const char *text, char out[LC_SHA256_HEX_SIZE])
{
    uint32_t state[8] = {0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                         0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19};
    size_t length = strlen(text);
    size_t offset = 0;
    for (; offset + 64 <= length; offset += 64) {
        sha256_block(state, (const uint8_t *)text + offset);
    }

    uint8_t tail[128] = {0};
    size_t remaining = length - offset;
    memcpy(tail, text + offset, remaining);
    tail[remaining] = 0x80;
    size_t tail_size = remaining + 9 <= 64 ? 64 : 128;
    uint64_t bits = (uint64_t)length * 8;
    for (size_t i = 0; i < 8; ++i) {
        tail[tail_size - 1 - i] = (uint8_t)(bits >> (8 * i));
    }
    sha256_block(state, tail);
    if (tail_size == 128) {
        sha256_block(state, tail + 64);
    }

    for (size_t i = 0; i < 8; ++i) {
        snprintf(out + i * 8, 9, "%08x", (unsigned)state[i]);
    }
}

static bool hasher_sha256_hex(void *ctx, const char *text, char out[LC_SHA256_HEX_SIZE])
{
    (void)ctx;
    fake_sha256_hex(text, out);
    return true;
}

lc_hasher_port_t fake_hasher_port(void)
{
    return (lc_hasher_port_t){.ctx = NULL, .sha256_hex = hasher_sha256_hex};
}

/* ---- Provisioning gateway ---- */

static bool gateway_subscribe_reply(void *ctx, const char *client_id)
{
    fake_gateway_t *gateway = ctx;
    fake_trace("gateway.subscribe");
    ++gateway->subscribe_count;
    snprintf(gateway->client_id, sizeof(gateway->client_id), "%s", client_id);
    return gateway->subscribe_ok;
}

static bool gateway_publish_registration(void *ctx, const char *token, const char *client_id)
{
    fake_gateway_t *gateway = ctx;
    fake_trace("gateway.register");
    ++gateway->registration_count;
    snprintf(gateway->token, sizeof(gateway->token), "%s", token);
    snprintf(gateway->client_id, sizeof(gateway->client_id), "%s", client_id);
    return gateway->publish_ok;
}

void fake_gateway_init(fake_gateway_t *gateway)
{
    memset(gateway, 0, sizeof(*gateway));
    gateway->subscribe_ok = true;
    gateway->publish_ok = true;
}

lc_provisioning_gateway_port_t fake_gateway_port(fake_gateway_t *gateway)
{
    return (lc_provisioning_gateway_port_t){
        .ctx = gateway,
        .subscribe_reply = gateway_subscribe_reply,
        .publish_registration = gateway_publish_registration,
    };
}

/* ---- System ---- */

static void system_restart(void *ctx)
{
    fake_system_t *system = ctx;
    fake_trace("system.restart");
    ++system->restart_count;
}

lc_system_port_t fake_system_port(fake_system_t *system)
{
    return (lc_system_port_t){.ctx = system, .restart = system_restart};
}
