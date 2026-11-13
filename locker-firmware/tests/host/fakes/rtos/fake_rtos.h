#pragma once

#include "freertos/FreeRTOS.h"

/*
 * The FreeRTOS queues, binary semaphore and tick count of main/owner_queue.c on
 * the host: single-threaded, never blocking. A queue holds copies, as the real
 * one does, so a test sees the same full and empty behaviour. A semaphore holds
 * zero or one token; a take with none left times out, and the tick count then
 * moves on by the whole timeout.
 */

void fake_rtos_reset(void);
/* Warnings and errors logged since the reset. */
unsigned fake_log_count(void);
/* Takes made since the reset, the timeout of the last one, and how many timed out. */
unsigned fake_take_count(void);
TickType_t fake_last_take_ticks(void);
unsigned fake_take_timeouts(void);
/* Time a take that succeeds uses up, as when the wake came late. */
void fake_rtos_set_wake_cost(TickType_t ticks);
/* The next xQueueSend of any queue fails, as if that queue were full. */
void fake_rtos_fail_next_send(void);
