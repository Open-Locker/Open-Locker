#include "lc_journal/journal.h"

#include <string.h>

/*
 * Format, version 1. See
 * docs/adr/0070-esp32-command-journal-record-format-and-retention.md.
 *
 * Each of the two regions starts with a header, written after its records:
 *
 *   0..3    magic             "OLJR"
 *   4       version           0x01
 *   5..7    0xFF
 *   8..11   generation        little endian, 1 to 0xFFFFFFFE
 *   12..15  header check      CRC-32 of bytes 0..11
 *
 * Records follow it:
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
 * The payload holds length-prefixed texts (one length byte, then the text)
 * and single bytes.
 */

#define MAGIC 0xA7
#define VERSION 0x01
#define COMMIT 0xC3
#define ERASED 0xFF

#define HEADER_SIZE 6
#define TRAILER_SIZE 5
#define PAYLOAD_MAX 320
#define RECORD_MAX (HEADER_SIZE + PAYLOAD_MAX + TRAILER_SIZE)

#define REGION_HEADER_SIZE 16
#define REGION_VERSION 0x01
#define GENERATION_MAX 0xFFFFFFFEu

typedef enum {
    TYPE_CLAIM = 1,
    TYPE_COMPLETION = 2,
    TYPE_DELIVERED = 3,
    TYPE_MESSAGE = 4,
} record_type_t;

typedef enum {
    STATE_CLAIMED,
    STATE_COMPLETED,
    STATE_DELIVERED,
} state_t;

typedef struct {
    uint8_t bytes[RECORD_MAX];
    size_t size;
} record_t;

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

static uint32_t get_le32(const uint8_t *data)
{
    return (uint32_t)data[0] | ((uint32_t)data[1] << 8) | ((uint32_t)data[2] << 16) |
           ((uint32_t)data[3] << 24);
}

static void put_le32(uint8_t *data, uint32_t value)
{
    for (size_t i = 0; i < 4; ++i) {
        data[i] = (uint8_t)(value >> (8 * i));
    }
}

/* FNV-1a. A match is always confirmed against the text in flash. */
static uint64_t hash_text(const char *text)
{
    uint64_t hash = 0xcbf29ce484222325u;
    for (; *text != '\0'; ++text) {
        hash ^= (uint8_t)*text;
        hash *= 0x100000001b3u;
    }
    return hash;
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

static reader_t payload_reader(const record_t *record)
{
    return (reader_t){.data = record->bytes + HEADER_SIZE,
                      .length = record->size - HEADER_SIZE - TRAILER_SIZE,
                      .ok = true};
}

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

static bool parse_completion(const record_t *record, lc_command_result_t *result)
{
    reader_t reader = payload_reader(record);
    memset(result, 0, sizeof(*result));
    take_text(&reader, result->transaction_id, sizeof(result->transaction_id));
    take_text(&reader, result->action_name, sizeof(result->action_name));
    uint8_t error = take_byte(&reader);
    take_text(&reader, result->applied_config_hash, sizeof(result->applied_config_hash));
    result->error = (lc_error_t)error;
    return reader.ok && reader.position == reader.length && result->transaction_id[0] != '\0' &&
           error <= LC_ERROR_UNKNOWN_ERROR;
}

static size_t record_size(size_t payload_length)
{
    return HEADER_SIZE + payload_length + TRAILER_SIZE;
}

static record_type_t record_type(const record_t *record)
{
    return (record_type_t)record->bytes[2];
}

/* ---- Flash ---- */

static bool fail(lc_journal_t *journal)
{
    journal->healthy = false;
    return false;
}

static size_t region_base(const lc_journal_t *journal, unsigned region)
{
    return region * journal->region_size;
}

static size_t active_end(const lc_journal_t *journal)
{
    return region_base(journal, journal->active) + journal->region_size;
}

static bool read_at(const lc_journal_t *journal, size_t offset, void *out, size_t length)
{
    return offset <= journal->io.capacity && length <= journal->io.capacity - offset &&
           journal->io.read(journal->io.ctx, offset, out, length);
}

/* The offset of the last programmed byte in [start, end), or start - 1 when there is none. */
static bool last_programmed(const lc_journal_t *journal, size_t start, size_t end, long *last)
{
    uint8_t chunk[64];

    while (end > start) {
        size_t length = end - start < sizeof(chunk) ? end - start : sizeof(chunk);
        size_t from = end - length;
        if (!read_at(journal, from, chunk, length)) {
            return false;
        }
        for (size_t i = length; i > 0; --i) {
            if (chunk[i - 1] != ERASED) {
                *last = (long)(from + i - 1);
                return true;
            }
        }
        end = from;
    }
    *last = (long)start - 1;
    return true;
}

static bool range_erased(const lc_journal_t *journal, size_t start, size_t end, bool *erased)
{
    long last;
    if (!last_programmed(journal, start, end, &last)) {
        return false;
    }
    *erased = last < (long)start;
    return true;
}

static bool read_region_header(const lc_journal_t *journal, unsigned region,
                               uint32_t *generation, bool *valid)
{
    uint8_t header[REGION_HEADER_SIZE];
    if (!read_at(journal, region_base(journal, region), header, sizeof(header))) {
        return false;
    }
    *generation = get_le32(header + 8);
    *valid = memcmp(header, "OLJR", 4) == 0 && header[4] == REGION_VERSION &&
             header[5] == ERASED && header[6] == ERASED && header[7] == ERASED &&
             *generation >= 1 && *generation <= GENERATION_MAX &&
             get_le32(header + 12) == crc32(header, 12);
    return true;
}

static bool write_region_header(lc_journal_t *journal, unsigned region, uint32_t generation)
{
    uint8_t header[REGION_HEADER_SIZE] = {'O', 'L', 'J', 'R', REGION_VERSION, ERASED, ERASED, ERASED};
    put_le32(header + 8, generation);
    put_le32(header + 12, crc32(header, 12));
    return journal->io.write(journal->io.ctx, region_base(journal, region), header, sizeof(header));
}

static bool header_valid(const uint8_t *header, size_t *payload_length)
{
    *payload_length = (size_t)header[3] | ((size_t)header[4] << 8);
    return header[0] == MAGIC && header[1] == VERSION && header[2] >= TYPE_CLAIM &&
           header[2] <= TYPE_MESSAGE && crc8(header + 1, 4) == header[5] &&
           *payload_length <= PAYLOAD_MAX;
}

static bool record_checks(const record_t *record)
{
    size_t body = record->size - TRAILER_SIZE;
    return record->bytes[record->size - 1] == COMMIT &&
           crc32(record->bytes + 1, body - 1) == get_le32(record->bytes + body);
}

/* Reads a committed record of the active region. Anything else marks the journal unhealthy. */
static bool load_record(lc_journal_t *journal, size_t offset, record_t *record)
{
    size_t payload_length;
    if (!read_at(journal, offset, record->bytes, HEADER_SIZE) ||
        !header_valid(record->bytes, &payload_length)) {
        return fail(journal);
    }
    record->size = record_size(payload_length);
    if (offset + record->size > active_end(journal) ||
        !read_at(journal, offset, record->bytes, record->size) || !record_checks(record)) {
        return fail(journal);
    }
    return true;
}

/* The `nth` text of the record at `offset`, counting from 0. */
static bool record_text(lc_journal_t *journal, size_t offset, unsigned nth,
                        char out[LC_ID_MAX + 1])
{
    record_t record;
    if (!load_record(journal, offset, &record)) {
        return false;
    }
    reader_t reader = payload_reader(&record);
    for (unsigned i = 0; i <= nth; ++i) {
        take_text(&reader, out, LC_ID_MAX + 1);
    }
    return reader.ok || fail(journal);
}

static bool text_matches(lc_journal_t *journal, size_t offset, unsigned nth, const char *text)
{
    char stored[LC_ID_MAX + 1];
    return record_text(journal, offset, nth, stored) && strcmp(stored, text) == 0;
}

/* ---- Index ---- */

/* -1 when unknown. A read that fails marks the journal unhealthy. */
static long find_transaction(lc_journal_t *journal, const char *transaction_id)
{
    uint64_t hash = hash_text(transaction_id);
    for (size_t i = 0; i < journal->count; ++i) {
        if (journal->transactions[i].hash == hash &&
            text_matches(journal, journal->transactions[i].claim, 0, transaction_id)) {
            return (long)i;
        }
    }
    return -1;
}

static long find_message(lc_journal_t *journal, const char *message_id)
{
    uint64_t hash = hash_text(message_id);
    for (size_t i = 0; i < journal->message_count; ++i) {
        if (journal->messages[i].hash == hash &&
            text_matches(journal, journal->messages[i].record, 1, message_id)) {
            return (long)i;
        }
    }
    return -1;
}

static void add_message(lc_journal_t *journal, size_t transaction, const char *message_id,
                        size_t offset)
{
    journal->messages[journal->message_count++] = (lc_journal_message_t){
        .hash = hash_text(message_id),
        .record = (uint32_t)offset,
        .transaction = (uint16_t)transaction,
    };
}

/* Applies one committed record at `offset` to the index. False when it makes no sense. */
static bool apply_record(lc_journal_t *journal, size_t offset, const record_t *record)
{
    reader_t reader = payload_reader(record);
    char transaction_id[LC_ID_MAX + 1];

    take_text(&reader, transaction_id, sizeof(transaction_id));
    if (!reader.ok || transaction_id[0] == '\0') {
        return false;
    }
    long index = find_transaction(journal, transaction_id);
    if (!journal->healthy) {
        return false;
    }
    lc_journal_transaction_t *transaction = index >= 0 ? &journal->transactions[index] : NULL;

    switch (record_type(record)) {
    case TYPE_CLAIM: {
        char message_id[LC_ID_MAX + 1];
        char action_name[LC_ACTION_NAME_MAX + 1];
        take_text(&reader, message_id, sizeof(message_id));
        take_text(&reader, action_name, sizeof(action_name));
        if (!reader.ok || reader.position != reader.length || message_id[0] == '\0' ||
            transaction != NULL || journal->count == LC_JOURNAL_TRANSACTIONS_MAX ||
            journal->message_count == LC_JOURNAL_MESSAGES_MAX ||
            find_message(journal, message_id) >= 0 || !journal->healthy) {
            return false;
        }
        journal->transactions[journal->count] = (lc_journal_transaction_t){
            .hash = hash_text(transaction_id),
            .claim = (uint32_t)offset,
            .bytes = (uint32_t)record->size,
            .id_length = (uint8_t)strlen(transaction_id),
            .action_length = (uint8_t)strlen(action_name),
            .state = STATE_CLAIMED,
        };
        add_message(journal, journal->count, message_id, offset);
        ++journal->count;
        ++journal->open_count;
        return true;
    }
    case TYPE_COMPLETION: {
        lc_command_result_t result;
        if (!parse_completion(record, &result) || transaction == NULL ||
            transaction->state != STATE_CLAIMED) {
            return false;
        }
        transaction->state = STATE_COMPLETED;
        transaction->completion = (uint32_t)offset;
        transaction->bytes += (uint32_t)record->size;
        return true;
    }
    case TYPE_DELIVERED:
        if (reader.position != reader.length || transaction == NULL ||
            transaction->state != STATE_COMPLETED) {
            return false;
        }
        transaction->state = STATE_DELIVERED;
        transaction->bytes += (uint32_t)record->size;
        --journal->open_count;
        return true;
    case TYPE_MESSAGE: {
        char message_id[LC_ID_MAX + 1];
        take_text(&reader, message_id, sizeof(message_id));
        if (!reader.ok || reader.position != reader.length || message_id[0] == '\0' ||
            transaction == NULL || journal->message_count == LC_JOURNAL_MESSAGES_MAX ||
            find_message(journal, message_id) >= 0 || !journal->healthy) {
            return false;
        }
        add_message(journal, (size_t)index, message_id, offset);
        return true;
    }
    }
    return false;
}

static bool load_entry(lc_journal_t *journal, size_t index, lc_journal_entry_t *out)
{
    const lc_journal_transaction_t *transaction = &journal->transactions[index];
    char message_id[LC_ID_MAX + 1];
    record_t record;

    memset(out, 0, sizeof(*out));
    if (!load_record(journal, transaction->claim, &record)) {
        return false;
    }
    reader_t reader = payload_reader(&record);
    take_text(&reader, out->transaction_id, sizeof(out->transaction_id));
    take_text(&reader, message_id, sizeof(message_id));
    take_text(&reader, out->action_name, sizeof(out->action_name));
    if (!reader.ok) {
        return fail(journal);
    }
    out->completed = transaction->state != STATE_CLAIMED;
    out->delivered = transaction->state == STATE_DELIVERED;
    if (transaction->completion != 0 &&
        (!load_record(journal, transaction->completion, &record) ||
         !parse_completion(&record, &out->result))) {
        return fail(journal);
    }
    return true;
}

/* ---- Reading the active region ---- */

typedef enum {
    SCAN_RECORD,
    SCAN_END,
    SCAN_TORN,
    SCAN_CORRUPT,
} scan_result_t;

/*
 * Reads the record at `offset`. Only the record that reaches `last`, the last
 * programmed byte, may be torn, and only when that byte is not a commit
 * marker: a committed record always ends in one, so damage to it is never
 * mistaken for a torn write.
 */
static scan_result_t scan_record(const lc_journal_t *journal, size_t offset, size_t end,
                                 long last, record_t *record)
{
    size_t room = end - offset;
    size_t head = room < HEADER_SIZE ? room : HEADER_SIZE;
    size_t payload_length;

    if (!read_at(journal, offset, record->bytes, 1)) {
        return SCAN_CORRUPT;
    }
    /*
     * The log ends here when nothing follows. With bytes after it, an erased
     * first byte is a damaged header: a cut page program can leave any of its
     * bytes erased, the first one included.
     */
    if (record->bytes[0] == ERASED && last < (long)offset) {
        return SCAN_END;
    }
    if (!read_at(journal, offset, record->bytes, head)) {
        return SCAN_CORRUPT;
    }
    if (head < HEADER_SIZE || !header_valid(record->bytes, &payload_length) ||
        record_size(payload_length) > room) {
        uint8_t tail;
        if (last > (long)(offset + RECORD_MAX - 1) || !read_at(journal, (size_t)last, &tail, 1)) {
            return SCAN_CORRUPT;
        }
        return tail == COMMIT ? SCAN_CORRUPT : SCAN_TORN;
    }
    record->size = record_size(payload_length);
    if (!read_at(journal, offset, record->bytes, record->size)) {
        return SCAN_CORRUPT;
    }
    if (record->bytes[record->size - 1] != COMMIT) {
        return last <= (long)(offset + record->size - 1) ? SCAN_TORN : SCAN_CORRUPT;
    }
    return record_checks(record) ? SCAN_RECORD : SCAN_CORRUPT;
}

/* Rebuilds the index from the active region. */
static bool scan(lc_journal_t *journal)
{
    size_t offset = region_base(journal, journal->active) + REGION_HEADER_SIZE;
    size_t end = active_end(journal);
    long last;

    journal->count = 0;
    journal->message_count = 0;
    journal->open_count = 0;
    journal->torn = false;
    if (!last_programmed(journal, offset, end, &last)) {
        return fail(journal);
    }
    while (offset < end) {
        record_t record;
        scan_result_t result = scan_record(journal, offset, end, last, &record);
        if (result == SCAN_END) {
            break;
        }
        if (result == SCAN_TORN) {
            journal->torn = true;
            break;
        }
        if (result == SCAN_CORRUPT || !apply_record(journal, offset, &record)) {
            return fail(journal);
        }
        offset += record.size;
    }
    journal->next_offset = offset;
    return journal->healthy;
}

bool lc_journal_open(lc_journal_t *journal, lc_flash_io_t io)
{
    uint32_t generation[2];
    bool valid[2];

    memset(journal, 0, sizeof(*journal));
    journal->io = io;
    journal->healthy = true;
    if (io.capacity == 0 || io.capacity % (2 * LC_JOURNAL_SECTOR_SIZE) != 0 || io.erase == NULL) {
        return fail(journal);
    }
    journal->region_size = io.capacity / 2;
    for (unsigned region = 0; region < 2; ++region) {
        if (!read_region_header(journal, region, &generation[region], &valid[region])) {
            return fail(journal);
        }
    }

    if (!valid[0] && !valid[1]) {
        /* Empty only if nothing but a cut first header was written: a corrupt header is never "nothing was done". */
        bool erased;
        if (!range_erased(journal, REGION_HEADER_SIZE, io.capacity, &erased) || !erased) {
            return fail(journal);
        }
        journal->next_offset = REGION_HEADER_SIZE;
        return true;
    }
    if (valid[0] && valid[1] && generation[0] == generation[1]) {
        return fail(journal);
    }
    /* Two valid headers: a restart between committing a region and invalidating the other. */
    journal->active = valid[1] && (!valid[0] || generation[1] > generation[0]) ? 1 : 0;
    journal->generation = generation[journal->active];
    journal->started = true;
    return scan(journal);
}

/* ---- Room ---- */

static size_t completion_room(const lc_journal_transaction_t *transaction)
{
    return record_size(1 + transaction->id_length + 1 + transaction->action_length + 1 +
                       LC_CONFIG_HASH_SIZE);
}

static size_t delivered_room(const lc_journal_transaction_t *transaction)
{
    return record_size(1 + transaction->id_length);
}

/* Room that must stay free so that an open transaction can still be finished. */
static size_t reserved_for(const lc_journal_transaction_t *transaction)
{
    switch (transaction->state) {
    case STATE_CLAIMED:
        return completion_room(transaction) + delivered_room(transaction);
    case STATE_COMPLETED:
        return delivered_room(transaction);
    default:
        return 0;
    }
}

static bool fits(const lc_journal_t *journal, size_t bytes, size_t more_reserved)
{
    size_t reserved = more_reserved;
    for (size_t i = 0; i < journal->count; ++i) {
        reserved += reserved_for(&journal->transactions[i]);
    }
    size_t free_bytes = active_end(journal) - journal->next_offset;
    return bytes <= free_bytes && reserved <= free_bytes - bytes;
}

/*
 * Copies the records still needed into the spare region, in log order, and
 * makes it the active one: every open transaction, and the newest delivered
 * ones until LC_JOURNAL_DELIVERED_KEEP are kept or the copy reaches half a
 * region. Further message IDs are copied only for open transactions, and only
 * in the room the budget has left; a delivered transaction keeps the message
 * ID of its claim. A later copy under a dropped ID is still caught by its
 * transaction ID and gets the stored response again.
 */
static bool compact(lc_journal_t *journal)
{
    bool keep[LC_JOURNAL_TRANSACTIONS_MAX];
    size_t budget = journal->region_size / 2;
    size_t used = 0;
    size_t kept = 0;

    for (size_t i = 0; i < journal->count; ++i) {
        keep[i] = journal->transactions[i].state != STATE_DELIVERED;
        used += keep[i] ? journal->transactions[i].bytes : 0;
    }
    for (size_t i = journal->count; i-- > 0;) {
        const lc_journal_transaction_t *transaction = &journal->transactions[i];
        if (transaction->state != STATE_DELIVERED) {
            continue;
        }
        if (kept == LC_JOURNAL_DELIVERED_KEEP || used + transaction->bytes > budget) {
            break;
        }
        keep[i] = true;
        used += transaction->bytes;
        ++kept;
    }

    size_t message_room = budget > used ? budget - used : 0;
    unsigned spare = 1 - journal->active;
    size_t out = region_base(journal, spare) + REGION_HEADER_SIZE;
    size_t offset = region_base(journal, journal->active) + REGION_HEADER_SIZE;
    while (offset < journal->next_offset) {
        record_t record;
        char transaction_id[LC_ID_MAX + 1];
        if (!load_record(journal, offset, &record)) {
            return false;
        }
        offset += record.size;
        reader_t reader = payload_reader(&record);
        take_text(&reader, transaction_id, sizeof(transaction_id));
        long index = reader.ok ? find_transaction(journal, transaction_id) : -1;
        if (index < 0) {
            return fail(journal);
        }
        if (!keep[index]) {
            continue;
        }
        if (record_type(&record) == TYPE_MESSAGE) {
            if (journal->transactions[index].state == STATE_DELIVERED ||
                record.size > message_room) {
                continue;
            }
            message_room -= record.size;
        }
        if (out + record.size > region_base(journal, spare) + journal->region_size ||
            !journal->io.write(journal->io.ctx, out, record.bytes, record.size)) {
            return fail(journal);
        }
        out += record.size;
    }

    /* From the header write on, the new region is active; the old one is invalidated at once. */
    if (journal->generation >= GENERATION_MAX ||
        !write_region_header(journal, spare, journal->generation + 1) ||
        !journal->io.erase(journal->io.ctx, region_base(journal, journal->active),
                           LC_JOURNAL_SECTOR_SIZE)) {
        return fail(journal);
    }
    journal->active = spare;
    ++journal->generation;
    journal->spare_ready = false;
    journal->spare_checked = true;
    journal->spare_erased = 1;
    journal->blank_failures = 0;
    journal->appended = false;
    return scan(journal);
}

/* The first append of an empty journal gives region A its header. */
static bool start(lc_journal_t *journal)
{
    bool erased;
    if (journal->started) {
        return true;
    }
    if (!range_erased(journal, 0, REGION_HEADER_SIZE, &erased) ||
        (!erased && !journal->io.erase(journal->io.ctx, 0, LC_JOURNAL_SECTOR_SIZE)) ||
        !write_region_header(journal, 0, 1)) {
        return fail(journal);
    }
    journal->started = true;
    journal->generation = 1;
    return true;
}

/* Room for a claim or a message record, compacting first when it is short, torn or out of slots. */
static bool make_room(lc_journal_t *journal, size_t bytes, size_t more_reserved, bool claim)
{
    for (int attempt = 0;; ++attempt) {
        bool slots = !claim || (journal->count < LC_JOURNAL_TRANSACTIONS_MAX &&
                                journal->message_count < LC_JOURNAL_MESSAGES_MAX);
        if (!journal->torn && slots && fits(journal, bytes, more_reserved)) {
            return true;
        }
        if (attempt > 0 || !journal->spare_ready || !compact(journal)) {
            return false;
        }
    }
}

/* Completions and deliveries always have their room reserved; only a torn tail stops them. */
static bool room_to_finish(lc_journal_t *journal, size_t bytes)
{
    if (journal->torn && (!journal->spare_ready || !compact(journal))) {
        return false;
    }
    return bytes <= active_end(journal) - journal->next_offset;
}

/*
 * Appends one record: everything but the commit marker, then the marker on its
 * own. A write that fails marks the journal unhealthy until the next start,
 * which finds out how far it got.
 */
static bool append(lc_journal_t *journal, record_type_t type, const uint8_t *payload,
                   size_t payload_length)
{
    record_t record;
    record.size = record_size(payload_length);
    record.bytes[0] = MAGIC;
    record.bytes[1] = VERSION;
    record.bytes[2] = (uint8_t)type;
    record.bytes[3] = (uint8_t)(payload_length & 0xFF);
    record.bytes[4] = (uint8_t)(payload_length >> 8);
    record.bytes[5] = crc8(record.bytes + 1, 4);
    memcpy(record.bytes + HEADER_SIZE, payload, payload_length);
    size_t body = HEADER_SIZE + payload_length;
    put_le32(record.bytes + body, crc32(record.bytes + 1, body - 1));
    record.bytes[record.size - 1] = COMMIT;

    size_t offset = journal->next_offset;
    if (!start(journal)) {
        return false;
    }
    if (!journal->io.write(journal->io.ctx, offset, record.bytes, record.size - 1) ||
        !journal->io.write(journal->io.ctx, offset + record.size - 1,
                           record.bytes + record.size - 1, 1)) {
        return fail(journal);
    }
    journal->next_offset += record.size;
    journal->appended = true;
    /* The callers checked everything this checks; a refusal here is a bug. */
    return apply_record(journal, offset, &record) || fail(journal);
}

/* ---- Background work ---- */

bool lc_journal_maintain(lc_journal_t *journal)
{
    if (!journal->healthy) {
        return false;
    }
    if (!journal->spare_ready) {
        size_t base = region_base(journal, 1 - journal->active);
        bool erased;
        /*
         * A spare that reads blank at the start is used as it is, so a boot loop
         * does not wear out the flash. An erase cut so late that it reads blank
         * but holds weak bits then shows up as a record that fails its check,
         * which stops the journal instead of passing silently.
         */
        if (!journal->spare_checked) {
            journal->spare_checked = true;
            if (!range_erased(journal, base, base + journal->region_size, &erased)) {
                return fail(journal);
            }
            journal->spare_ready = erased;
            return true;
        }
        if (journal->spare_erased < journal->region_size / LC_JOURNAL_SECTOR_SIZE) {
            if (!journal->io.erase(journal->io.ctx,
                                   base + journal->spare_erased * LC_JOURNAL_SECTOR_SIZE,
                                   LC_JOURNAL_SECTOR_SIZE)) {
                return fail(journal);
            }
            ++journal->spare_erased;
            return true;
        }
        if (!range_erased(journal, base, base + journal->region_size, &erased)) {
            return fail(journal);
        }
        if (erased) {
            journal->spare_ready = true;
        } else if (++journal->blank_failures >= 2) {
            return fail(journal);
        } else {
            journal->spare_erased = 0;
        }
        return true;
    }
    size_t used = journal->next_offset - region_base(journal, journal->active);
    if (journal->started &&
        (journal->torn || (journal->appended && used > journal->region_size / 4 * 3))) {
        return compact(journal);
    }
    return false;
}

/* ---- Port ---- */

static bool port_healthy(void *ctx)
{
    return ((lc_journal_t *)ctx)->healthy;
}

static bool port_seen_message(void *ctx, const char *message_id)
{
    return find_message(ctx, message_id) >= 0;
}

static bool port_find(void *ctx, const char *transaction_id, lc_journal_entry_t *out)
{
    long index = find_transaction(ctx, transaction_id);
    return index >= 0 && load_entry(ctx, (size_t)index, out);
}

static bool port_claim(void *ctx, const char *transaction_id, const char *message_id,
                       const char *action_name)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    if (!journal->healthy || transaction_id[0] == '\0' || message_id[0] == '\0' ||
        strlen(action_name) > LC_ACTION_NAME_MAX ||
        journal->open_count == LC_JOURNAL_OPEN_MAX) {
        return false;
    }
    put_text(&writer, transaction_id);
    put_text(&writer, message_id);
    put_text(&writer, action_name);
    if (!writer.ok || find_transaction(journal, transaction_id) >= 0 ||
        find_message(journal, message_id) >= 0 || !journal->healthy) {
        return false;
    }
    /* Room for this claim and for finishing it, as well as for every open one. */
    const lc_journal_transaction_t fresh = {
        .id_length = (uint8_t)strlen(transaction_id),
        .action_length = (uint8_t)strlen(action_name),
        .state = STATE_CLAIMED,
    };
    return make_room(journal, record_size(writer.length), reserved_for(&fresh), true) &&
           append(journal, TYPE_CLAIM, payload, writer.length);
}

static bool port_remember_message(void *ctx, const char *transaction_id, const char *message_id)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    if (!journal->healthy || message_id[0] == '\0' ||
        find_transaction(journal, transaction_id) < 0) {
        return false;
    }
    if (find_message(journal, message_id) >= 0) {
        return true;
    }
    if (!journal->healthy || journal->message_count == LC_JOURNAL_MESSAGES_MAX) {
        return false;
    }
    put_text(&writer, transaction_id);
    put_text(&writer, message_id);
    return writer.ok && make_room(journal, record_size(writer.length), 0, false) &&
           append(journal, TYPE_MESSAGE, payload, writer.length);
}

static bool port_complete(void *ctx, const lc_command_result_t *result)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    long index = find_transaction(journal, result->transaction_id);
    if (!journal->healthy || index < 0 ||
        journal->transactions[index].state != STATE_CLAIMED ||
        (unsigned)result->error > (unsigned)LC_ERROR_UNKNOWN_ERROR) {
        return false;
    }
    put_text(&writer, result->transaction_id);
    put_text(&writer, result->action_name);
    put_byte(&writer, (uint8_t)result->error);
    put_text(&writer, result->applied_config_hash);
    return writer.ok && room_to_finish(journal, record_size(writer.length)) &&
           append(journal, TYPE_COMPLETION, payload, writer.length);
}

static bool port_mark_delivered(void *ctx, const char *transaction_id)
{
    lc_journal_t *journal = ctx;
    uint8_t payload[PAYLOAD_MAX];
    writer_t writer = {.data = payload, .capacity = sizeof(payload), .ok = true};

    long index = find_transaction(journal, transaction_id);
    if (!journal->healthy || index < 0 ||
        journal->transactions[index].state == STATE_CLAIMED) {
        return false;
    }
    if (journal->transactions[index].state == STATE_DELIVERED) {
        return true;
    }
    put_text(&writer, transaction_id);
    return writer.ok && room_to_finish(journal, record_size(writer.length)) &&
           append(journal, TYPE_DELIVERED, payload, writer.length);
}

static size_t port_count(void *ctx)
{
    return ((lc_journal_t *)ctx)->open_count;
}

static bool port_at(void *ctx, size_t index, lc_journal_entry_t *out)
{
    lc_journal_t *journal = ctx;
    for (size_t i = 0; i < journal->count; ++i) {
        if (journal->transactions[i].state != STATE_DELIVERED && index-- == 0) {
            return load_entry(journal, i, out);
        }
    }
    return false;
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
