#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/*
 * UART transport for a half-duplex serial bus behind an automatic-direction
 * converter: there is no DE/RE line to drive. The write and read functions
 * have the shape the bus adapter's serial struct expects; the composition
 * root wires them in.
 */

typedef enum {
    LC_SERIAL_PARITY_NONE = 0,
    LC_SERIAL_PARITY_EVEN,
    LC_SERIAL_PARITY_ODD,
} lc_serial_parity_t;

typedef struct {
    int uart_num;
    int tx_pin;
    int rx_pin;
    int baud_rate;
    lc_serial_parity_t parity;
    /* 1 or 2. */
    int stop_bits;
} lc_serial_uart_config_t;

typedef struct {
    int uart_num;
    bool ready;
} lc_serial_uart_t;

/* Configures the UART. Transmits nothing. */
bool lc_serial_uart_init(lc_serial_uart_t *uart, const lc_serial_uart_config_t *config);

/*
 * Returns the number of bytes known to be on the wire: `length` on success, a
 * negative value when none were queued, and fewer than `length` when
 * transmission did not finish in time.
 */
int lc_serial_uart_write(void *ctx, const uint8_t *data, size_t length);

/*
 * Returns the bytes already received, at most `capacity`; with none, waits up
 * to `timeout_ms` for the first. 0 on timeout, negative on error.
 */
int lc_serial_uart_read(void *ctx, uint8_t *buffer, size_t capacity, uint32_t timeout_ms);
