#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "lc_ports/clock_port.h"
#include "lc_rs485_lock_board/serial.h"

/*
 * Scripted serial line with a virtual clock.
 *
 * Time moves only when a read waits, or through fake_serial_advance(). A read
 * that finds nothing advances the clock by its timeout, or up to the arrival
 * of the next scripted bytes if that is sooner. A write takes no time unless
 * fake_serial_set_write_duration() says so.
 */

#define FAKE_SERIAL_MAX_WRITES 16
#define FAKE_SERIAL_WRITE_MAX 16
#define FAKE_SERIAL_MAX_CHUNKS 16
#define FAKE_SERIAL_CHUNK_MAX 128

typedef struct {
    bool armed;
    int after_write;
    uint32_t delay_ms;
    int64_t at_ms;
    uint8_t data[FAKE_SERIAL_CHUNK_MAX];
    size_t length;
    size_t consumed;
} fake_serial_chunk_t;

typedef struct {
    int64_t at_ms;
    uint8_t data[FAKE_SERIAL_WRITE_MAX];
    /* Bytes the fake accepted; 0 when the write was made to fail. */
    size_t length;
} fake_serial_write_t;

typedef struct {
    int64_t now_ms;
    fake_serial_chunk_t chunks[FAKE_SERIAL_MAX_CHUNKS];
    size_t chunk_count;
    fake_serial_write_t writes[FAKE_SERIAL_MAX_WRITES];
    size_t write_count;
    bool override_next_write;
    int next_write_result;
    uint32_t write_duration_ms;
    uint32_t noise_period_ms;
    int64_t noise_until_ms;
} fake_serial_t;

void fake_serial_init(fake_serial_t *fake);
lc_rs485_serial_t fake_serial_port(fake_serial_t *fake);
lc_clock_port_t fake_serial_clock(fake_serial_t *fake);

/* Bytes that are in the receive buffer at the current virtual time. */
void fake_serial_receive_now(fake_serial_t *fake, const uint8_t *data, size_t length);
/* Bytes that arrive `delay_ms` after the write call with this zero-based index. */
void fake_serial_reply_to_write(fake_serial_t *fake, size_t write_index, uint32_t delay_ms,
                                const uint8_t *data, size_t length);
/* The next write returns `result` instead of accepting every byte. */
void fake_serial_set_next_write_result(fake_serial_t *fake, int result);
/* Every write returns this long after it started, as a UART waiting for the last bit does. */
void fake_serial_set_write_duration(fake_serial_t *fake, uint32_t milliseconds);
/* One byte of noise every `period_ms` until `until_ms`, whatever else is scripted. */
void fake_serial_set_noise(fake_serial_t *fake, uint32_t period_ms, int64_t until_ms);
void fake_serial_advance(fake_serial_t *fake, uint32_t milliseconds);

/* Number of write calls, including ones made to fail. */
size_t fake_serial_write_count(const fake_serial_t *fake);
int64_t fake_serial_write_time(const fake_serial_t *fake, size_t write_index);
const fake_serial_write_t *fake_serial_write_at(const fake_serial_t *fake, size_t write_index);
