#pragma once
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "cJSON.h"

#define LC_PAYLOAD_MAX 8192
#define LC_ID_MAX 128
#define LC_COMPARTMENTS_MAX 64
#define LC_FRAME_MAX 64
#define LC_RESPONSE_MAX 768
#define LC_RECORDS_MAX 64
#define LC_MESSAGES_MAX 128
#define LC_SLOT_SIZE 1536

typedef enum { LC_UNKNOWN, LC_CLOSED, LC_OPEN } lc_door_t;
typedef enum { LC_NOT_SENT, LC_ACKNOWLEDGED, LC_UNCERTAIN } lc_unlock_t;
typedef struct { int number; uint8_t board, address; } lc_mapping_t;
typedef struct {
    bool applied, opening_feedback;
    unsigned heartbeat_seconds;
    size_t count;
    lc_mapping_t mappings[LC_COMPARTMENTS_MAX];
    char hash[65];
} lc_config_t;
typedef struct {
    char data[LC_PAYLOAD_MAX + 1];
    size_t received, total;
    bool active;
} lc_assembler_t;
/* One assembly per client; reject gaps, overlap, oversize and embedded NUL. */
bool lc_assemble(lc_assembler_t *a, const void *data, size_t length,
                 size_t offset, size_t total);
bool lc_encode(uint8_t board, unsigned address, bool unlock, uint8_t out[5]);
bool lc_ack(const uint8_t *frame, size_t length, uint8_t board, unsigned address);
bool lc_status(const uint8_t *frame, size_t length, uint8_t board,
               bool opening_feedback, lc_door_t states[255]);
bool lc_string(const cJSON *object, const char *key, char *out, size_t capacity);
bool lc_integer(const cJSON *object, const char *key, int minimum, int maximum, int *out);
cJSON *lc_parse_json(const char *text);
bool lc_timestamp(const char *text);
bool lc_wifi_password(const char *text);
/* Caller hashes canonical output with SHA-256 and compares against config.hash. */
bool lc_parse_config(const cJSON *data, lc_config_t *config, char **canonical);
cJSON *lc_config_json(const lc_config_t *config);

typedef struct {
    char transaction[LC_ID_MAX + 1], message[LC_ID_MAX + 1], action[32];
    char response[LC_RESPONSE_MAX];
    bool completed, delivered;
} lc_record_t;
typedef struct {
    void *context;
    size_t capacity;
    bool (*read)(void *, size_t, void *, size_t);
    bool (*write)(void *, size_t, const void *, size_t);
} lc_journal_io_t;
typedef struct {
    lc_journal_io_t io;
    size_t next_slot, count;
    bool healthy;
    lc_record_t records[LC_RECORDS_MAX];
    size_t message_count;
    struct { char id[LC_ID_MAX + 1]; uint8_t owner; } messages[LC_MESSAGES_MAX];
} lc_journal_t;
bool lc_journal_open(lc_journal_t *j, lc_journal_io_t io);
const lc_record_t *lc_journal_find(const lc_journal_t *j, const char *transaction);
bool lc_journal_seen(const lc_journal_t *j, const char *message);
/* Claims reserve space for both completion and delivery before actuation. */
bool lc_journal_claim(lc_journal_t *j, const char *transaction, const char *message, const char *action);
bool lc_journal_complete(lc_journal_t *j, const char *transaction, const char *response);
bool lc_journal_delivered(lc_journal_t *j, const char *transaction);
bool lc_journal_pending(lc_journal_t *j, const char *transaction);
bool lc_journal_remember(lc_journal_t *j, const char *transaction, const char *message);

typedef struct {
    void *context;
    bool (*hash)(void *, const char *, char[65]);
    bool (*save_config)(void *, const lc_config_t *);
    lc_unlock_t (*unlock)(void *, uint8_t, unsigned);
    bool (*read_states)(void *, uint8_t, bool, lc_door_t[255]);
    bool (*publish)(void *, const char *suffix, cJSON *body, bool retain);
    bool (*connected)(void *);
} lc_app_ports_t;
typedef struct {
    lc_app_ports_t ports;
    lc_journal_t *journal;
    lc_config_t config;
    lc_door_t states[LC_COMPARTMENTS_MAX];
    lc_door_t last_known[LC_COMPARTMENTS_MAX];
    unsigned unknown_reads[LC_COMPARTMENTS_MAX];
    char detecting[LC_COMPARTMENTS_MAX][LC_ID_MAX + 1];
    int64_t fired_at[LC_COMPARTMENTS_MAX];
    int64_t started_at, next_heartbeat;
    bool force_snapshot, closing;
} lc_app_t;
void lc_app_init(lc_app_t *app, lc_app_ports_t ports, lc_journal_t *journal,
                 const lc_config_t *config, int64_t now_ms);
void lc_app_dispatch(lc_app_t *app, const char *json, int64_t now_ms);
void lc_app_recover(lc_app_t *app);
void lc_app_flush(lc_app_t *app);
void lc_app_poll(lc_app_t *app, int64_t now_ms);
