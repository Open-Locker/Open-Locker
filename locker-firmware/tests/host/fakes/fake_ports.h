#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_domain/command.h"
#include "lc_domain/events.h"
#include "lc_domain/identity.h"
#include "lc_ports/clock_port.h"
#include "lc_ports/command_journal_port.h"
#include "lc_ports/hasher_port.h"
#include "lc_ports/locker_bus_port.h"
#include "lc_ports/outbound_port.h"
#include "lc_ports/provisioning_gateway_port.h"
#include "lc_ports/store_ports.h"
#include "lc_ports/system_port.h"

/*
 * In-memory stand-ins for the application ports. Each records what was called
 * and can be told to fail. Calls are also written to one shared trace, so a
 * test can assert the order of calls across ports.
 */

/* ---- Trace ---- */

void fake_trace_reset(void);
void fake_trace(const char *what);
/* Position of the first such call, or -1. */
int fake_trace_first(const char *what);
unsigned fake_trace_count(const char *what);

/* ---- Clock ---- */

typedef struct {
    int64_t now_ms;
} fake_clock_t;

lc_clock_port_t fake_clock_port(fake_clock_t *clock);

/* ---- Locker bus ---- */

#define FAKE_BUS_ADDRESSES 255

typedef struct {
    /* What a read reports, by address. Unknown from address 24 up by default. */
    lc_door_t doors[FAKE_BUS_ADDRESSES];
    bool read_ok;
    lc_unlock_result_t unlock_result;
    lc_bus_state_t state;
    bool apply_profile_ok;
    unsigned unlock_count;
    unsigned read_count;
    unsigned profile_count;
    lc_target_t last_target;
    lc_hardware_profile_t last_profile;
    /* Called in the middle of every read. */
    void (*during_read)(void *hook_ctx);
    void *hook_ctx;
} fake_bus_t;

void fake_bus_init(fake_bus_t *bus);
lc_locker_bus_port_t fake_bus_port(fake_bus_t *bus);

/* ---- Command journal ---- */

#define FAKE_JOURNAL_ENTRIES 16
#define FAKE_JOURNAL_MESSAGES 32

typedef struct {
    bool healthy;
    bool refuse_claim;
    bool fail_complete;
    lc_journal_entry_t entries[FAKE_JOURNAL_ENTRIES];
    size_t count;
    char messages[FAKE_JOURNAL_MESSAGES][LC_ID_MAX + 1];
    size_t message_count;
    unsigned claim_count;
    unsigned complete_count;
    unsigned delivered_count;
} fake_journal_t;

void fake_journal_init(fake_journal_t *journal);
lc_command_journal_port_t fake_journal_port(fake_journal_t *journal);
/* NULL when the transaction was never claimed. */
const lc_journal_entry_t *fake_journal_entry(const fake_journal_t *journal,
                                             const char *transaction_id);

/* ---- Outbound ---- */

typedef enum {
    FAKE_RESPONSE = 0,
    FAKE_HEARTBEAT,
    FAKE_SNAPSHOT,
    FAKE_OPEN_DETECTION,
    FAKE_UNCOMMANDED_OPEN,
    FAKE_OFFLINE,
    FAKE_PUBLISH_KINDS,
} fake_publish_kind_t;

#define FAKE_OUTBOUND_RESPONSES 16
#define FAKE_OUTBOUND_EVENTS 8
#define FAKE_OUTBOUND_IDS 512

typedef struct {
    /* How many of the next publishes of each kind are refused. */
    unsigned refuse[FAKE_PUBLISH_KINDS];
    unsigned attempts[FAKE_PUBLISH_KINDS];
    unsigned accepted[FAKE_PUBLISH_KINDS];
    lc_publish_id_t last_id[FAKE_PUBLISH_KINDS];

    lc_command_result_t responses[FAKE_OUTBOUND_RESPONSES];
    size_t response_count;
    uint32_t heartbeat_uptime_seconds;
    bool heartbeat_bus_connected;
    lc_compartment_state_t snapshot[LC_COMPARTMENTS_MAX];
    size_t snapshot_count;
    lc_open_detection_t detections[FAKE_OUTBOUND_EVENTS];
    size_t detection_count;
    lc_uncommanded_open_t uncommanded[FAKE_OUTBOUND_EVENTS];
    size_t uncommanded_count;
    lc_offline_reason_t offline_reason;

    /* Every accepted publish, in order. */
    lc_publish_id_t ids[FAKE_OUTBOUND_IDS];
    size_t id_count;
    /* How many of `ids` a test has acknowledged so far. */
    size_t acknowledged;
} fake_outbound_t;

void fake_outbound_init(fake_outbound_t *outbound);
lc_outbound_port_t fake_outbound_port(fake_outbound_t *outbound);
/* Publish calls of every kind, accepted or not. */
unsigned fake_outbound_attempts(const fake_outbound_t *outbound);
/* The accepted response for a transaction that was published last, or NULL. */
const lc_command_result_t *fake_outbound_last_response(const fake_outbound_t *outbound);
/* The state the last snapshot gave for a compartment; -1 when it is not in it. */
int fake_outbound_snapshot_state(const fake_outbound_t *outbound, uint16_t compartment_number);

/* ---- Stores ---- */

typedef struct {
    bool has;
    lc_config_t config;
    bool save_ok;
    bool clear_ok;
    unsigned save_count;
    unsigned clear_count;
} fake_config_store_t;

void fake_config_store_init(fake_config_store_t *store);
lc_config_store_port_t fake_config_store_port(fake_config_store_t *store);

typedef struct {
    bool has;
    lc_identity_t identity;
    bool save_ok;
    unsigned save_count;
} fake_identity_store_t;

void fake_identity_store_init(fake_identity_store_t *store);
lc_identity_store_port_t fake_identity_store_port(fake_identity_store_t *store);

typedef struct {
    bool has;
    lc_network_settings_t settings;
    bool save_ok;
    bool clear_ok;
    unsigned save_count;
    unsigned clear_count;
} fake_settings_store_t;

void fake_settings_store_init(fake_settings_store_t *store);
lc_settings_store_port_t fake_settings_store_port(fake_settings_store_t *store);

/* ---- Hasher: a real SHA-256 ---- */

lc_hasher_port_t fake_hasher_port(void);
void fake_sha256_hex(const char *text, char out[LC_SHA256_HEX_SIZE]);

/* ---- Provisioning gateway ---- */

typedef struct {
    bool subscribe_ok;
    bool publish_ok;
    unsigned subscribe_count;
    unsigned registration_count;
    char token[LC_CREDENTIAL_MAX + 1];
    char client_id[LC_CLIENT_ID_MAX + 1];
} fake_gateway_t;

void fake_gateway_init(fake_gateway_t *gateway);
lc_provisioning_gateway_port_t fake_gateway_port(fake_gateway_t *gateway);

/* ---- System ---- */

typedef struct {
    unsigned restart_count;
} fake_system_t;

lc_system_port_t fake_system_port(fake_system_t *system);
