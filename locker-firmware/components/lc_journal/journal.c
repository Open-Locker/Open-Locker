#include "lc_journal/journal.h"

#include <string.h>

#include "lc_domain/text.h"

/*
 * Record format, version 1. See
 * docs/adr/0068-esp32-command-journal-record-format-and-retention.md.
 *
 *   0       magic             0xA7
 *   1       version           0x01
 *   2       type              claim, completion, delivered, message
 *   3..4    payload length    little endian
 *   5       header check      CRC-8 of bytes 1..4
 *   6..     payload
 *   ..+4    record check      CRC-32 of bytes 1 up to the end of the payload
 *   last    commit marker     0xC3, written last and on its own
 *
 * The payload holds length-prefixed strings: one length byte, then the text.
 */

#define MAGIC 0xA7
#define VERSION 0x01
#define COMMIT 0xC3
#define ERASED 0xFF
/* A void byte: torn debris that was made unreadable before the log went on. */
#define VOID_BYTE 0x00

#define HEADER_SIZE 6
#define TRAILER_SIZE 5
#define PAYLOAD_MAX 320

typedef enum {
    TYPE_CLAIM = 1,
    TYPE_COMPLETION = 2,
    TYPE_DELIVERED = 3,
    TYPE_MESSAGE = 4,
} record_type_t;

/* ---- Checks ---- */

static uint8_t crc8(const uint8_t *data, size_t length)
{
    uint8_t crc = 0;
    for (size_t i = 0; i < length; ++i) {
        crc ^= data[i];
        for (int bit = 0; bit < 8; ++bit) {
            crc = (uint8_t)((crc & 0x80) ? (crc << 1) ^ 0x07 : crc << 1);
        }
    }
    return crc;
}

static uint32_t crc32(const uint8_t *data, size_t length)
{
    uint32_t crc = 0xFFFFFFFFu;
    for (size_t i = 0; i < length; ++i) {
        crc ^= data[i];
        for (int bit = 0; bit < 8; ++bit) {
            crc = (crc & 1) ? (crc >> 1) ^ 0xEDB88320u : crc >> 1;
        }
    }
    return ~crc;
}

/* ---- Payload coding ---- */

typedef struct {
    uint8_t *data;
    size_t capacity;
    size_t length;
    bool ok;
} writer_t;

static void put_text(writer_t *writer, const char *text)
{
    size_t length = strlen(text);
    if (length > LC_ID_MAX || writer->length + 1 + length > writer->capacity) {
        writer->ok = false;
        return;
    }
    writer->data[writer->length++] = (uint8_t)length;
    memcpy(writer->data + writer->length, text, length);
    writer->length += length;
}

static void put_byte(writer_t *writer, uint8_t value)
{
    if (writer->length + 1 > writer->capacity) {
        writer->ok = false;
        return;
    }
    writer->data[writer->length++] = value;
}

typedef struct {
    const uint8_t *data;
    size_t length;
    size_t position;
    bool ok;
} reader_t;

static uint8_t take_byte(reader_t *reader)
{
    if (reader->position + 1 > reader->length) {
        reader->ok = false;
        return 0;
    }
    return reader->data[reader->position++];
}

/* Copies one text into a field of `capacity` bytes. Text may not contain NUL. */
static void take_text(reader_t *reader, char *out, size_t capacity)
{
    size_t length = take_byte(reader);
    if (!reader->ok || reader->position + length > reader->length || length >= capacity) {
        reader->ok = false;
        return;
    }
    memcpy(out, reader->data + reader->position, length);
    out[length] = '\0';
    if (strlen(out) != length) {
        reader->ok = false;
    }
    reader->position += length;
}

/* Sizes of the records of one transaction, for reserving room. */
static size_t record_size(size_t payload_length)
{
    return HEADER_SIZE + payload_length + TRAILER_SIZE;
}

static size_t completion_payload_max(const lc_journal_entry_t *entry)
{
    return 1 + strlen(entry->transaction_id) + 1 + strlen(entry->action_name) + 1 + 1 +
           (LC_CONFIG_HASH_SIZE - 1);
}

static size_t delivered_payload(const lc_journal_entry_t *entry)
{
    return 1 + strlen(entry->transaction_id);
}

/* Room that must stay free so every claim can still be completed and delivered. */
static size_t reserved_for(const lc_journal_entry_t *entry)
{
    size_t reserved = 0;
    if (!entry->completed) {
        reserved += record_size(completion_payload_max(entry));
    }
    if (!entry->delivered) {
        reserved += record_size(delivered_payload(entry));
    }
    return reserved;
}

static size_t reserved_total(const lc_journal_t *journal)
{
    size_t reserved = 0;
    for (size_t i = 0; i < journal->count; ++i) {
        reserved += reserved_for(&journal->entries[i]);
    }
    return reserved;
}

/* ---- Index ---- */

static lc_journal_entry_t *find_entry(lc_journal_t *journal, const char *transaction_id,
                                      size_t *index)
{
    for (size_t i = 0; i < journal->count; ++i) {
        if (strcmp(journal->entries[i].transaction_id, transaction_id) == 0) {
            if (index != NULL) {
                *index = i;
            }
            return &journal->entries[i];
        }
    }
    return NULL;
}

static bool message_seen(const lc_journal_t *journal, const char *message_id)
{
    for (size_t i = 0; i < journal->message_count; ++i) {
        if (strcmp(journal->messages[i].id, message_id) == 0) {
            return true;
        }
    }
    return false;
}

static bool index_message(lc_journal_t *journal, size_t owner, const char *message_id)
{
    if (journal->message_count == LC_JOURNAL_MESSAGES_MAX || message_id[0] == '\0' ||
        !lc_text_copy(journal->messages[journal->message_count].id,
                      sizeof(journal->messages[0].id), message_id)) {
        return false;
    }
    journal->messages[journal->message_count].owner = (uint8_t)owner;
    ++journal->message_count;
    return true;
}

/* Applies one committed record to the index. False when it makes no sense. */
static bool apply_record(lc_journal_t *journal, record_type_t type, const uint8_t *payload,
                         size_t length)
{
    reader_t reader = {.data = payload, .length = length, .ok = true};
    char transaction_id[LC_ID_MAX + 1];
    size_t index;

    take_text(&reader, transaction_id, sizeof(transaction_id));
    if (!reader.ok || transaction_id[0] == '\0') {
        return false;
    }
    lc_journal_entry_t *entry = find_entry(journal, transaction_id, &index);

    switch (type) {
    case TYPE_CLAIM: {
        char message_id[LC_ID_MAX + 1];
        char action_name[LC_ACTION_NAME_MAX + 1];
        take_text(&reader, message_id, sizeof(message_id));
        take_text(&reader, action_name, sizeof(action_name));
        if (!reader.ok || reader.position != length || entry != NULL ||
            journal->count == LC_JOURNAL_TRANSACTIONS_MAX ||
            message_seen(journal, message_id)) {
            return false;
        }
        entry = &journal->entries[journal->count];
        memset(entry, 0, sizeof(*entry));
        (void)lc_text_copy(entry->transaction_id, sizeof(entry->transaction_id), transaction_id);
        (void)lc_text_copy(entry->action_name, sizeof(entry->action_name), action_name);
        if (!index_message(journal, journal->count, message_id)) {
            return false;
        }
        ++journal->count;
        return true;
    }
    case TYPE_COMPLETION: {
        lc_command_result_t result;
        memset(&result, 0, sizeof(result));
        (void)lc_text_copy(result.transaction_id, sizeof(result.transaction_id), transaction_id);
        take_text(&reader, result.action_name, sizeof(result.action_name));
        uint8_t error = take_byte(&reader);
        take_text(&reader, result.applied_config_hash, sizeof(result.applied_config_hash));
        if (!reader.ok || reader.position != length || entry == NULL || entry->completed ||
            error > LC_ERROR_UNKNOWN_ERROR) {
            return false;
        }
        result.error = (lc_error_t)error;
        entry->completed = true;
        entry->result = result;
        return true;
    }
    case TYPE_DELIVERED:
        if (reader.position != length || entry == NULL || !entry->completed ||
            entry->delivered) {
            return false;
        }
        entry->delivered = true;
        return true;
    case TYPE_MESSAGE: {
        char message_id[LC_ID_MAX + 1];
        take_text(&reader, message_id, sizeof(message_id));
        if (!reader.ok || reader.position != length || entry == NULL ||
            message_seen(journal, message_id)) {
            return false;
        }
        return index_message(journal, index, message_id);
    }
    }
    return false;
}

/* ---- Reading the log ---- */

static bool read_at(const lc_journal_t *journal, size_t offset, void *out, size_t length)
{
    return offset <= journal->io.capacity && length <= journal->io.capacity - offset &&
           journal->io.read(journal->io.ctx, offset, out, length);
}

/* The offset of the last programmed byte, or -1 when the whole region is erased. */
static bool last_programmed(const lc_journal_t *journal, long *last)
{
    uint8_t chunk[64];
    size_t end = journal->io.capacity;

    while (end > 0) {
        size_t length = end < sizeof(chunk) ? end : sizeof(chunk);
        size_t start = end - length;
        if (!read_at(journal, start, chunk, length)) {
            return false;
        }
        for (size_t i = length; i > 0; --i) {
            if (chunk[i - 1] != ERASED) {
                *last = (long)(start + i - 1);
                return true;
            }
        }
        end = start;
    }
    *last = -1;
    return true;
}

typedef enum {
    SCAN_RECORD,
    SCAN_END,
    SCAN_TORN_TAIL,
    SCAN_CORRUPT,
} scan_result_t;

/* Reads the record at `offset` into `record`; `*size` is its length. */
static scan_result_t scan_record(const lc_journal_t *journal, size_t offset, long last,
                                 uint8_t *record, size_t *size)
{
    uint8_t first;
    if (!read_at(journal, offset, &first, 1)) {
        return SCAN_CORRUPT;
    }
    if (first == ERASED) {
        /* The log ends here, and nothing may follow. */
        return last < (long)offset ? SCAN_END : SCAN_CORRUPT;
    }
    if (first != MAGIC) {
        return SCAN_CORRUPT;
    }
    /* Not even the header landed: a write that was cut short. */
    if (last < (long)(offset + HEADER_SIZE - 1)) {
        return SCAN_TORN_TAIL;
    }

    if (!read_at(journal, offset, record, HEADER_SIZE)) {
        return SCAN_CORRUPT;
    }
    size_t payload_length = (size_t)record[3] | ((size_t)record[4] << 8);
    if (record[1] != VERSION || record[2] < TYPE_CLAIM || record[2] > TYPE_MESSAGE ||
        crc8(record + 1, 4) != record[5] || payload_length > PAYLOAD_MAX) {
        return SCAN_CORRUPT;
    }
    *size = record_size(payload_length);
    if (*size > journal->io.capacity - offset ||
        !read_at(journal, offset, record, *size)) {
        return SCAN_CORRUPT;
    }

    uint8_t marker = record[*size - 1];
    if (marker == ERASED) {
        /* Written up to the commit marker, which never landed. Nothing may follow. */
        return last < (long)(offset + *size - 1) ? SCAN_TORN_TAIL : SCAN_CORRUPT;
    }
    if (marker != COMMIT) {
        return SCAN_CORRUPT;
    }
    size_t body = HEADER_SIZE + payload_length;
    uint32_t stored = (uint32_t)record[body] | ((uint32_t)record[body + 1] << 8) |
                      ((uint32_t)record[body + 2] << 16) | ((uint32_t)record[body + 3] << 24);
    if (crc32(record + 1, body - 1) != stored) {
        return SCAN_CORRUPT;
    }
    return SCAN_RECORD;
}

bool lc_journal_open(lc_journal_t *journal, lc_flash_io_t io)
{
    memset(journal, 0, sizeof(*journal));
    journal->io = io;
    journal->healthy = true;

    long last;
    if (!last_programmed(journal, &last)) {
        journal->healthy = false;
        return false;
    }

    size_t offset = 0;
    for (;;) {
        uint8_t first;
        if (offset >= io.capacity) {
            break;
        }
        if (!read_at(journal, offset, &first, 1)) {
            journal->healthy = false;
            break;
        }
        /* Debris of a torn write that a later append made unreadable. */
        if (first == VOID_BYTE) {
            ++offset;
            continue;
        }

        uint8_t record[HEADER_SIZE + PAYLOAD_MAX + TRAILER_SIZE];
        size_t size = 0;
        scan_result_t result = scan_record(journal, offset, last, record, &size);
        if (result == SCAN_END) {
            break;
        }
        if (result == SCAN_TORN_TAIL) {
            journal->torn_start = offset;
            journal->torn = true;
            offset = (size_t)(last + 1);
            break;
        }
        if (result == SCAN_CORRUPT ||
            !apply_record(journal, (record_type_t)record[2], record + HEADER_SIZE,
                          size - HEADER_SIZE - TRAILER_SIZE)) {
            journal->healthy = false;
            break;
        }
        offset += size;
    }
    journal->next_offset = offset;
    return journal->healthy;
}

/* ---- Appending ---- */

/* Makes the debris of a torn write unreadable, so the log can go on behind it. */
static bool neutralize_torn_tail(lc_journal_t *journal)
{
    uint8_t zeros[32];
    memset(zeros, VOID_BYTE, sizeof(zeros));

    size_t offset = journal->torn_start;
    while (offset < journal->next_offset) {
        size_t length = journal->next_offset - offset;
        if (length > sizeof(zeros)) {
            length = sizeof(zeros);
        }
        if (!journal->io.write(journal->io.ctx, offset, zeros, length)) {
            return false;
        }
        offset += length;
    }
    journal->torn = false;
    return true;
}

/*
 * Appends one record: everything but the commit marker, then the marker on its
 * own. A write that fails marks the journal unhealthy until the next start,
 * which finds out how far it got.
 */
static bool append(lc_journal_t *journal, record_type_t type, const uint8_t *payload,
                   size_t payload_length)
{
    uint8_t record[HEADER_SIZE + PAYLOAD_MAX + TRAILER_SIZE];
    size_t size = record_size(payload_length);

    record[0] = MAGIC;
    record[1] = VERSION;
    record[2] = (uint8_t)type;
    record[3] = (uint8_t)(payload_length & 0xFF);
    record[4] = (uint8_t)(payload_length >> 8);
    record[5] = crc8(record + 1, 4);
    memcpy(record + HEADER_SIZE, payload, payload_length);
    size_t body = HEADER_SIZE + payload_length;
    uint32_t check = crc32(record + 1, body - 1);
    for (size_t i = 0; i < 4; ++i) {
        record[body + i] = (uint8_t)(check >> (8 * i));
    }
    record[size - 1] = COMMIT;

    if (journal->torn && !neutralize_torn_tail(journal)) {
        journal->healthy = false;
        return false;
    }
    if (!journal->io.write(journal->io.ctx, journal->next_offset, record, size - 1) ||
        !journal->io.write(journal->io.ctx, journal->next_offset + size - 1, record + size - 1, 1)) {
        journal->healthy = false;
        return false;
    }
    journal->next_offset += size;
    return true;
}

static bool has_room(const lc_journal_t *journal, size_t record_bytes, size_t more_reserved)
{
    size_t used = journal->next_offset;
    size_t reserved = reserved_total(journal) + more_reserved;
    return used <= journal->io.capacity && record_bytes <= journal->io.capacity - used &&
           reserved <= journal->io.capacity - used - record_bytes;
}

/* ---- Port ---- */

static bool port_healthy(void *ctx)
{
    return ((lc_journal_t *)ctx)->healthy;
}

static bool port_seen_message(void *ctx, const char *message_id)
{
    return message_seen(ctx, message_id);
}

static bool port_find(void *ctx, const char *transaction_id, lc_journal_entry_t *out)
{
    const lc_journal_entry_t *entry = find_entry(ctx, transaction_id, NULL);
    if (entry == NULL) {
        return false;
    }
    *out = *entry;
    return true;
}

static bool port_claim(void *ctx, const char *transaction_id, const char *message_id,
                       const char *action_name)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    if (!journal->healthy || transaction_id[0] == '\0' || message_id[0] == '\0' ||
        journal->count == LC_JOURNAL_TRANSACTIONS_MAX ||
        journal->message_count == LC_JOURNAL_MESSAGES_MAX ||
        find_entry(journal, transaction_id, NULL) != NULL || message_seen(journal, message_id)) {
        return false;
    }
    put_text(&writer, transaction_id);
    put_text(&writer, message_id);
    put_text(&writer, action_name);
    if (!writer.ok) {
        return false;
    }

    lc_journal_entry_t fresh;
    memset(&fresh, 0, sizeof(fresh));
    if (!lc_text_copy(fresh.transaction_id, sizeof(fresh.transaction_id), transaction_id) ||
        !lc_text_copy(fresh.action_name, sizeof(fresh.action_name), action_name)) {
        return false;
    }
    /* Room for this claim and for finishing it, as well as for all earlier ones. */
    if (!has_room(journal, record_size(writer.length), reserved_for(&fresh))) {
        return false;
    }
    if (!append(journal, TYPE_CLAIM, payload, writer.length)) {
        return false;
    }
    journal->entries[journal->count] = fresh;
    (void)index_message(journal, journal->count, message_id);
    ++journal->count;
    return true;
}

static bool port_remember_message(void *ctx, const char *transaction_id, const char *message_id)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};
    size_t index;

    if (!journal->healthy || message_id[0] == '\0' ||
        find_entry(journal, transaction_id, &index) == NULL) {
        return false;
    }
    if (message_seen(journal, message_id)) {
        return true;
    }
    if (journal->message_count == LC_JOURNAL_MESSAGES_MAX) {
        return false;
    }
    put_text(&writer, transaction_id);
    put_text(&writer, message_id);
    if (!writer.ok || !has_room(journal, record_size(writer.length), 0) ||
        !append(journal, TYPE_MESSAGE, payload, writer.length)) {
        return false;
    }
    return index_message(journal, index, message_id);
}

static bool port_complete(void *ctx, const lc_command_result_t *result)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    lc_journal_entry_t *entry = find_entry(journal, result->transaction_id, NULL);
    if (!journal->healthy || entry == NULL || entry->completed ||
        (unsigned)result->error > (unsigned)LC_ERROR_UNKNOWN_ERROR) {
        return false;
    }
    put_text(&writer, result->transaction_id);
    put_text(&writer, result->action_name);
    put_byte(&writer, (uint8_t)result->error);
    put_text(&writer, result->applied_config_hash);
    if (!writer.ok || record_size(writer.length) > journal->io.capacity - journal->next_offset ||
        !append(journal, TYPE_COMPLETION, payload, writer.length)) {
        return false;
    }
    entry->completed = true;
    entry->result = *result;
    return true;
}

static bool port_mark_delivered(void *ctx, const char *transaction_id)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    lc_journal_entry_t *entry = find_entry(journal, transaction_id, NULL);
    if (!journal->healthy || entry == NULL || !entry->completed) {
        return false;
    }
    if (entry->delivered) {
        return true;
    }
    put_text(&writer, transaction_id);
    if (!writer.ok || record_size(writer.length) > journal->io.capacity - journal->next_offset ||
        !append(journal, TYPE_DELIVERED, payload, writer.length)) {
        return false;
    }
    entry->delivered = true;
    return true;
}

static size_t port_count(void *ctx)
{
    return ((lc_journal_t *)ctx)->count;
}

static bool port_at(void *ctx, size_t index, lc_journal_entry_t *out)
{
    const lc_journal_t *journal = ctx;
    if (index >= journal->count) {
        return false;
    }
    *out = journal->entries[index];
    return true;
}

lc_command_journal_port_t lc_journal_port(lc_journal_t *journal)
{
    return (lc_command_journal_port_t){
        .ctx = journal,
        .healthy = port_healthy,
        .seen_message = port_seen_message,
        .find = port_find,
        .claim = port_claim,
        .remember_message = port_remember_message,
        .complete = port_complete,
        .mark_delivered = port_mark_delivered,
        .count = port_count,
        .at = port_at,
    };
}
