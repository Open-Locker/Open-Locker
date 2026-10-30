#include "app_fixture.h"

#include <assert.h>
#include <stdio.h>
#include <string.h>

fake_clock_t fx_clock;
fake_bus_t fx_bus;
fake_journal_t fx_journal;
fake_outbound_t fx_outbound;
fake_config_store_t fx_config_store;
fake_update_t fx_update;
fake_system_t fx_system;
lc_app_t fx_app;

#define STEP_MS 100

static lc_app_ports_t ports(void)
{
    return (lc_app_ports_t){
        .bus = fake_bus_port(&fx_bus),
        .journal = fake_journal_port(&fx_journal),
        .outbound = fake_outbound_port(&fx_outbound),
        .config_store = fake_config_store_port(&fx_config_store),
        .hasher = fake_hasher_port(),
        .clock = fake_clock_port(&fx_clock),
        .firmware_update = fake_update_port(&fx_update),
        .system = fake_system_port(&fx_system),
    };
}

void fx_init(void)
{
    fake_trace_reset();
    fx_clock.now_ms = 1000;
    fake_bus_init(&fx_bus);
    fake_journal_init(&fx_journal);
    fake_outbound_init(&fx_outbound);
    fake_config_store_init(&fx_config_store);
    fake_update_init(&fx_update);
    memset(&fx_system, 0, sizeof(fx_system));
    fx_restart();
}

void fx_restart(void)
{
    const lc_app_ports_t wired = ports();
    lc_app_init(&fx_app, &wired);
}

void fx_connect(void)
{
    lc_app_on_connected(&fx_app);
}

static lc_command_t command(const char *transaction_id, const char *message_id,
                            const char *action_name, lc_action_t action)
{
    lc_command_t result;
    memset(&result, 0, sizeof(result));
    snprintf(result.transaction_id, sizeof(result.transaction_id), "%s", transaction_id);
    snprintf(result.message_id, sizeof(result.message_id), "%s", message_id);
    snprintf(result.action_name, sizeof(result.action_name), "%s", action_name);
    result.action = action;
    return result;
}

lc_command_t fx_open(const char *transaction_id, const char *message_id,
                     uint16_t compartment_number)
{
    lc_command_t result =
        command(transaction_id, message_id, "open_compartment", LC_ACTION_OPEN_COMPARTMENT);
    result.compartment_number = compartment_number;
    return result;
}

lc_command_t fx_update_firmware(const char *transaction_id, const char *message_id,
                                const char *version, const char *url, bool allow_downgrade)
{
    lc_command_t result =
        command(transaction_id, message_id, "update_firmware", LC_ACTION_UPDATE_FIRMWARE);
    snprintf(result.firmware.version, sizeof(result.firmware.version), "%s", version);
    snprintf(result.firmware.url, sizeof(result.firmware.url), "%s", url);
    result.firmware.allow_downgrade = allow_downgrade;
    return result;
}

void fx_canonical(const lc_mapping_t *mappings, size_t count, lc_feedback_t feedback, char *out,
                  size_t capacity)
{
    assert(count <= LC_COMPARTMENTS_MAX);
    lc_mapping_t sorted[LC_COMPARTMENTS_MAX];
    memcpy(sorted, mappings, count * sizeof(mappings[0]));
    for (size_t i = 1; i < count; ++i) {
        lc_mapping_t moving = sorted[i];
        size_t position = i;
        while (position > 0 && sorted[position - 1].number > moving.number) {
            sorted[position] = sorted[position - 1];
            --position;
        }
        sorted[position] = moving;
    }

    int length = snprintf(
        out, capacity, "{\"adapter_type\":\"rs485_lock_board\",\"feedback_type\":\"%s\",\"compartments\":[",
        feedback == LC_FEEDBACK_DOOR_OPENING ? "door_opening" : "door_closing");
    for (size_t i = 0; i < count; ++i) {
        assert(length > 0 && (size_t)length < capacity);
        length += snprintf(out + length, capacity - (size_t)length,
                           "%s{\"compartment_number\":%u,\"slaveId\":%u,\"address\":%u}",
                           i == 0 ? "" : ",", (unsigned)sorted[i].number,
                           (unsigned)sorted[i].board, (unsigned)sorted[i].address);
    }
    assert(length > 0 && (size_t)length + 2 < capacity);
    snprintf(out + length, capacity - (size_t)length, "]}");
}

lc_command_t fx_apply(const char *transaction_id, const char *message_id,
                      const lc_mapping_t *mappings, size_t count, lc_feedback_t feedback,
                      uint32_t heartbeat_seconds)
{
    assert(count <= LC_COMPARTMENTS_MAX);
    lc_command_t result =
        command(transaction_id, message_id, "apply_config", LC_ACTION_APPLY_CONFIG);
    result.config.supported_adapter = true;
    result.config.feedback = feedback;
    result.config.heartbeat_seconds = heartbeat_seconds;
    result.config.count = count;
    memcpy(result.config.mappings, mappings, count * sizeof(mappings[0]));

    char canonical[LC_CONFIG_CANONICAL_MAX];
    fx_canonical(mappings, count, feedback, canonical, sizeof(canonical));
    fake_sha256_hex(canonical, result.config.hash);
    return result;
}

void fx_configure(void)
{
    static const lc_mapping_t MAPPINGS[] = {
        {.number = 1, .board = 1, .address = 0},
        {.number = 2, .board = 1, .address = 1},
    };
    fx_connect();
    const lc_command_t apply = fx_apply("fx-config-tx", "fx-config-msg", MAPPINGS, 2,
                                        LC_FEEDBACK_DOOR_CLOSING, FX_HEARTBEAT_SECONDS);
    lc_app_handle_command(&fx_app, &apply);
    fx_acknowledge_all();
    fx_reset_counts();
}

void fx_reset_counts(void)
{
    fake_trace_reset();
    fx_bus.unlock_count = 0;
    fx_bus.read_count = 0;
    fx_bus.profile_count = 0;
    fx_journal.claim_count = 0;
    fx_journal.complete_count = 0;
    fx_journal.delivered_count = 0;
    memset(fx_outbound.attempts, 0, sizeof(fx_outbound.attempts));
    memset(fx_outbound.accepted, 0, sizeof(fx_outbound.accepted));
    fx_outbound.response_count = 0;
    fx_outbound.detection_count = 0;
    fx_outbound.uncommanded_count = 0;
    fx_outbound.snapshot_count = 0;
    fx_outbound.failure_count = 0;
    fx_update.start_count = 0;
    fx_update.abort_count = 0;
    fx_update.mark_valid_count = 0;
    fx_update.rollback_count = 0;
    fx_system.restart_count = 0;
    fx_update.select_count = 0;
    fx_config_store.save_count = 0;
    fx_config_store.clear_count = 0;
}

void fx_acknowledge_all(void)
{
    while (fx_outbound.acknowledged < fx_outbound.id_count) {
        lc_app_on_publish_acknowledged(&fx_app, fx_outbound.ids[fx_outbound.acknowledged++]);
    }
}

static void advance(uint32_t milliseconds, bool acknowledge)
{
    for (uint32_t elapsed = 0; elapsed < milliseconds; elapsed += STEP_MS) {
        fx_clock.now_ms += STEP_MS;
        lc_app_tick(&fx_app);
        if (acknowledge) {
            fx_acknowledge_all();
        }
    }
}

void fx_advance(uint32_t milliseconds)
{
    advance(milliseconds, false);
}

void fx_advance_acknowledging(uint32_t milliseconds)
{
    advance(milliseconds, true);
}
