#include "locker_core.h"
#include <string.h>

/* No erase or compaction in V1. A partial/corrupt slot fails closed, including
 * a power cut before the claim commit marker. Never infer "not executed" from
 * missing completion. Production compaction/retention is a separate decision. */
#define MAGIC UINT32_C(0x4c434a31)
typedef struct { uint32_t magic, sequence, crc; lc_record_t record; uint32_t committed; } disk_record_t;
_Static_assert(sizeof(disk_record_t) <= LC_SLOT_SIZE, "journal record exceeds slot");

static uint32_t crc32(const void *data, size_t length)
{
    const uint8_t *bytes = data;
    uint32_t crc = UINT32_MAX;
    while (length--) {
        crc ^= *bytes++;
        for (unsigned bit = 0; bit < 8; ++bit) crc = (crc >> 1) ^ (UINT32_C(0xedb88320) & (0u - (crc & 1u)));
    }
    return ~crc;
}

static lc_record_t *find(lc_journal_t *j, const char *transaction)
{
    for (size_t i = 0; i < j->count; ++i)
        if (!strcmp(j->records[i].transaction, transaction)) return &j->records[i];
    return NULL;
}

const lc_record_t *lc_journal_find(const lc_journal_t *j, const char *transaction)
{
    for (size_t i = 0; i < j->count; ++i)
        if (!strcmp(j->records[i].transaction, transaction)) return &j->records[i];
    return NULL;
}

bool lc_journal_seen(const lc_journal_t *j, const char *message)
{
    for (size_t i = 0; i < j->message_count; ++i)
        if (!strcmp(j->messages[i].id, message)) return true;
    return false;
}

static bool remember(lc_journal_t *j, const lc_record_t *record, size_t owner)
{
    for (size_t i = 0; i < j->message_count; ++i)
        if (!strcmp(j->messages[i].id, record->message)) return j->messages[i].owner == owner;
    if (j->message_count == LC_MESSAGES_MAX) return false;
    strcpy(j->messages[j->message_count].id, record->message);
    j->messages[j->message_count++].owner = (uint8_t)owner;
    return true;
}

bool lc_journal_open(lc_journal_t *j, lc_journal_io_t io)
{
    memset(j, 0, sizeof(*j));
    j->io = io;
    for (; j->next_slot < io.capacity / LC_SLOT_SIZE; ++j->next_slot) {
        disk_record_t disk;
        if (!io.read(io.context, j->next_slot * LC_SLOT_SIZE, &disk, sizeof(disk))) return false;
        const uint8_t *bytes = (const uint8_t *)&disk;
        bool erased = true;
        for (size_t i = 0; i < sizeof(disk); ++i) erased &= bytes[i] == 0xff;
        if (erased) {
            /* A later committed slot after a hole is corruption, not an empty tail. */
            for (size_t slot = j->next_slot + 1; slot < io.capacity / LC_SLOT_SIZE; ++slot) {
                uint8_t probe[sizeof(disk_record_t)];
                if (!io.read(io.context, slot * LC_SLOT_SIZE, probe, sizeof(probe))) return false;
                for (size_t b = 0; b < sizeof(probe); ++b) if (probe[b] != 0xff) return false;
            }
            break;
        }
        lc_record_t *r = &disk.record;
        if (disk.magic != MAGIC || disk.committed != 0 || disk.sequence != j->next_slot ||
            disk.crc != crc32(r, sizeof(*r)) ||
            !memchr(r->transaction, 0, sizeof(r->transaction)) || !*r->transaction ||
            !memchr(r->message, 0, sizeof(r->message)) || !*r->message ||
            !memchr(r->action, 0, sizeof(r->action)) || !*r->action ||
            !memchr(r->response, 0, sizeof(r->response)) ||
            (r->completed != (*r->response != 0)) || (r->delivered && !r->completed)) return false;
        lc_record_t *existing = find(j, r->transaction);
        if (!existing) {
            if (j->count == LC_RECORDS_MAX || r->completed || lc_journal_seen(j, r->message)) return false;
            existing = &j->records[j->count++];
        } else if (strcmp(existing->action, r->action) ||
                   (existing->completed && (!r->completed || strcmp(existing->response, r->response)))) return false;
        if (!remember(j, r, (size_t)(existing - j->records))) return false;
        *existing = *r;
    }
    j->healthy = true;
    return true;
}

static bool append(lc_journal_t *j, const lc_record_t *record)
{
    if (!j->healthy || j->next_slot >= j->io.capacity / LC_SLOT_SIZE) return false;
    disk_record_t disk;
    memset(&disk, 0xff, sizeof(disk));
    disk.magic = MAGIC;
    disk.sequence = (uint32_t)j->next_slot;
    disk.record = *record;
    disk.crc = crc32(record, sizeof(*record));
    size_t offset = j->next_slot * LC_SLOT_SIZE;
    if (!j->io.write(j->io.context, offset, &disk, sizeof(disk))) {
        j->healthy = false;
        return false;
    }
    uint32_t commit = 0;
    if (!j->io.write(j->io.context, offset + offsetof(disk_record_t, committed), &commit, sizeof(commit))) {
        j->healthy = false;
        return false;
    }
    disk_record_t verify;
    if (!j->io.read(j->io.context, offset, &verify, sizeof(verify)) || verify.committed != 0 ||
        verify.crc != crc32(&verify.record, sizeof(verify.record))) {
        j->healthy = false;
        return false;
    }
    ++j->next_slot;
    return true;
}

bool lc_journal_claim(lc_journal_t *j, const char *transaction, const char *message, const char *action)
{
    size_t reservations = 3; /* this claim, completion, first delivery */
    for (size_t i = 0; i < j->count; ++i)
        reservations += !j->records[i].completed ? 2 : !j->records[i].delivered;
    if (!j->healthy || j->count == LC_RECORDS_MAX || j->message_count == LC_MESSAGES_MAX || !*transaction || !*message || !*action ||
        strlen(transaction) > LC_ID_MAX || strlen(message) > LC_ID_MAX || strlen(action) >= 32 ||
        find(j, transaction) || lc_journal_seen(j, message) ||
        reservations > j->io.capacity / LC_SLOT_SIZE - j->next_slot) return false;
    lc_record_t r = {0};
    strcpy(r.transaction, transaction);
    strcpy(r.message, message);
    strcpy(r.action, action);
    if (!append(j, &r)) return false;
    if (!remember(j, &r, j->count)) { j->healthy = false; return false; }
    j->records[j->count++] = r;
    return true;
}

bool lc_journal_complete(lc_journal_t *j, const char *transaction, const char *response)
{
    lc_record_t *r = find(j, transaction);
    if (!r || r->completed || !*response || strlen(response) >= sizeof(r->response)) return false;
    lc_record_t next = *r;
    next.completed = true;
    strcpy(next.response, response);
    if (!append(j, &next)) return false;
    *r = next;
    return true;
}

bool lc_journal_delivered(lc_journal_t *j, const char *transaction)
{
    lc_record_t *r = find(j, transaction);
    if (!r || !r->completed) return false;
    if (r->delivered) return true;
    lc_record_t next = *r;
    next.delivered = true;
    if (!append(j, &next)) return false;
    *r = next;
    return true;
}

bool lc_journal_pending(lc_journal_t *j, const char *transaction)
{
    lc_record_t *r = find(j, transaction);
    if (!r || !r->completed) return false;
    if (!r->delivered) return true;
    lc_record_t next = *r;
    next.delivered = false;
    if (!append(j, &next)) return false;
    *r = next;
    return true;
}

bool lc_journal_remember(lc_journal_t *j, const char *transaction, const char *message)
{
    lc_record_t *r = find(j, transaction);
    if (!r || !*message || strlen(message) > LC_ID_MAX || !j->healthy) return false;
    for (size_t i = 0; i < j->message_count; ++i)
        if (!strcmp(j->messages[i].id, message)) return j->messages[i].owner == (size_t)(r - j->records);
    size_t reservations = 1;
    for (size_t i = 0; i < j->count; ++i)
        reservations += !j->records[i].completed ? 2 : !j->records[i].delivered;
    if (j->message_count == LC_MESSAGES_MAX || reservations > j->io.capacity / LC_SLOT_SIZE - j->next_slot) return false;
    lc_record_t next = *r;
    strcpy(next.message, message);
    if (!append(j, &next)) return false;
    if (!remember(j, &next, (size_t)(r - j->records))) { j->healthy = false; return false; }
    *r = next;
    return true;
}
