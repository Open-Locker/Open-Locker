#include "fake_serial.h"

#include <assert.h>
#include <string.h>

#define NOT_TIED_TO_A_WRITE (-1)

static fake_serial_chunk_t *add_chunk(fake_serial_t *fake, const uint8_t *data, size_t length)
{
    assert(fake->chunk_count < FAKE_SERIAL_MAX_CHUNKS);
    assert(length > 0 && length <= FAKE_SERIAL_CHUNK_MAX);
    fake_serial_chunk_t *chunk = &fake->chunks[fake->chunk_count++];
    memset(chunk, 0, sizeof(*chunk));
    memcpy(chunk->data, data, length);
    chunk->length = length;
    return chunk;
}

/* Earliest armed chunk that still has bytes; scripting order breaks ties. */
static fake_serial_chunk_t *next_chunk(fake_serial_t *fake)
{
    fake_serial_chunk_t *earliest = NULL;
    for (size_t i = 0; i < fake->chunk_count; ++i) {
        fake_serial_chunk_t *chunk = &fake->chunks[i];
        if (!chunk->armed || chunk->consumed == chunk->length) {
            continue;
        }
        if (earliest == NULL || chunk->at_ms < earliest->at_ms) {
            earliest = chunk;
        }
    }
    return earliest;
}

static int fake_write(void *ctx, const uint8_t *data, size_t length)
{
    fake_serial_t *fake = ctx;
    assert(fake->write_count < FAKE_SERIAL_MAX_WRITES);
    assert(length <= FAKE_SERIAL_WRITE_MAX);

    int result = (int)length;
    if (fake->override_next_write) {
        fake->override_next_write = false;
        result = fake->next_write_result;
    }

    size_t index = fake->write_count++;
    fake_serial_write_t *write = &fake->writes[index];
    write->at_ms = fake->now_ms;
    write->length = result > 0 ? (size_t)result : 0;
    memcpy(write->data, data, write->length);

    for (size_t i = 0; i < fake->chunk_count; ++i) {
        fake_serial_chunk_t *chunk = &fake->chunks[i];
        if (!chunk->armed && chunk->after_write == (int)index) {
            chunk->armed = true;
            chunk->at_ms = fake->now_ms + chunk->delay_ms;
        }
    }
    fake->now_ms += fake->write_duration_ms;
    return result;
}

static int fake_read(void *ctx, uint8_t *buffer, size_t capacity, uint32_t timeout_ms)
{
    fake_serial_t *fake = ctx;

    if (fake->reads_until_error > 0 && --fake->reads_until_error == 0) {
        return -1;
    }
    if (fake->noise_period_ms > 0 && fake->now_ms < fake->noise_until_ms) {
        if (timeout_ms < fake->noise_period_ms) {
            fake->now_ms += timeout_ms;
            return 0;
        }
        fake->now_ms += fake->noise_period_ms;
        buffer[0] = 0x55;
        return 1;
    }

    fake_serial_chunk_t *chunk = next_chunk(fake);

    if (chunk == NULL || chunk->at_ms > fake->now_ms + timeout_ms) {
        fake->now_ms += timeout_ms;
        return 0;
    }
    if (chunk->at_ms > fake->now_ms) {
        fake->now_ms = chunk->at_ms;
    }

    size_t available = chunk->length - chunk->consumed;
    size_t count = available < capacity ? available : capacity;
    memcpy(buffer, chunk->data + chunk->consumed, count);
    chunk->consumed += count;
    return (int)count;
}

static int64_t fake_now_ms(void *ctx)
{
    const fake_serial_t *fake = ctx;
    return fake->now_ms;
}

void fake_serial_init(fake_serial_t *fake)
{
    memset(fake, 0, sizeof(*fake));
}

lc_rs485_serial_t fake_serial_port(fake_serial_t *fake)
{
    return (lc_rs485_serial_t){.ctx = fake, .write = fake_write, .read = fake_read};
}

lc_clock_port_t fake_serial_clock(fake_serial_t *fake)
{
    return (lc_clock_port_t){.ctx = fake, .now_ms = fake_now_ms};
}

void fake_serial_receive_now(fake_serial_t *fake, const uint8_t *data, size_t length)
{
    fake_serial_chunk_t *chunk = add_chunk(fake, data, length);
    chunk->after_write = NOT_TIED_TO_A_WRITE;
    chunk->at_ms = fake->now_ms;
    chunk->armed = true;
}

void fake_serial_reply_to_write(fake_serial_t *fake, size_t write_index, uint32_t delay_ms,
                                const uint8_t *data, size_t length)
{
    assert(write_index >= fake->write_count);
    fake_serial_chunk_t *chunk = add_chunk(fake, data, length);
    chunk->after_write = (int)write_index;
    chunk->delay_ms = delay_ms;
}

void fake_serial_set_next_write_result(fake_serial_t *fake, int result)
{
    fake->override_next_write = true;
    fake->next_write_result = result;
}

void fake_serial_fail_read(fake_serial_t *fake, unsigned nth)
{
    fake->reads_until_error = nth;
}

void fake_serial_set_write_duration(fake_serial_t *fake, uint32_t milliseconds)
{
    fake->write_duration_ms = milliseconds;
}

void fake_serial_set_noise(fake_serial_t *fake, uint32_t period_ms, int64_t until_ms)
{
    fake->noise_period_ms = period_ms;
    fake->noise_until_ms = until_ms;
}

void fake_serial_advance(fake_serial_t *fake, uint32_t milliseconds)
{
    fake->now_ms += milliseconds;
}

size_t fake_serial_write_count(const fake_serial_t *fake)
{
    return fake->write_count;
}

int64_t fake_serial_write_time(const fake_serial_t *fake, size_t write_index)
{
    return fake_serial_write_at(fake, write_index)->at_ms;
}

const fake_serial_write_t *fake_serial_write_at(const fake_serial_t *fake, size_t write_index)
{
    assert(write_index < fake->write_count);
    return &fake->writes[write_index];
}
