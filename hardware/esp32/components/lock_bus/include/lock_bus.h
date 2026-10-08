#pragma once

#include <stddef.h>
#include <stdint.h>
#include "esp_err.h"

#define LOCK_BUS_MAX_FRAME_SIZE 64

/** Initialize UART2 for the automatic-direction XY-485: TX=17, RX=16, 9600 8N1. */
esp_err_t lock_bus_init(void);

/**
 * Query standard-firmware panel status. Call from one task only; this component
 * owns UART2. Board addresses are 1..31.
 * The raw response and length are returned even if validation fails.
 * Status bit polarity/layout must be commissioned before interpreting door state.
 */
esp_err_t lock_bus_query_all(uint8_t board_address, uint8_t *response,
                           size_t capacity, size_t *length);

/** Send a single panel-timed unlock, without awaiting a reply or retrying.
 * Channel is one-based; zero (open all) is rejected. ESP_OK means transmitted,
 * not acknowledged or physically opened. Call only from the bus owner task.
 */
esp_err_t lock_bus_unlock(uint8_t board_address, uint8_t channel);
