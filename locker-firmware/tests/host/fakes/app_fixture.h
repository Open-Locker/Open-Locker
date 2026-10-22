#pragma once

#include <stddef.h>
#include <stdint.h>

#include "fake_ports.h"
#include "lc_application/app.h"

/*
 * The application wired to fakes for every port, shared by the suites that
 * test it. One instance; fx_init() resets it.
 */

extern fake_clock_t fx_clock;
extern fake_bus_t fx_bus;
extern fake_journal_t fx_journal;
extern fake_outbound_t fx_outbound;
extern fake_config_store_t fx_config_store;
extern fake_update_t fx_update;
extern lc_app_t fx_app;

/* Heartbeat interval of the configuration fx_configure() applies. */
#define FX_HEARTBEAT_SECONDS 15

/* Resets every fake and the trace, then initialises the application. Not connected. */
void fx_init(void);
/* Initialises the application again over the same fakes, as a restart would. */
void fx_restart(void);
void fx_connect(void);

lc_command_t fx_open(const char *transaction_id, const char *message_id,
                     uint16_t compartment_number);
lc_command_t fx_update_firmware(const char *transaction_id, const char *message_id,
                                const char *version, const char *url, bool allow_downgrade);
/* An apply_config whose hash is correct for the given mappings. */
lc_command_t fx_apply(const char *transaction_id, const char *message_id,
                      const lc_mapping_t *mappings, size_t count, lc_feedback_t feedback,
                      uint32_t heartbeat_seconds);

/*
 * The canonical JSON of ADR-0061, built independently of the firmware, as the
 * expected value: compartments sorted by number, no whitespace.
 */
void fx_canonical(const lc_mapping_t *mappings, size_t count, lc_feedback_t feedback, char *out,
                  size_t capacity);

/*
 * Connects and applies compartment 1 at board 1 address 0 and compartment 2 at
 * board 1 address 1, door_closing feedback, then acknowledges every publish
 * and resets the counters, so a test starts counting from zero.
 */
void fx_configure(void);

/* Zeroes the call counters, the recorded messages and the trace. Keeps all state. */
void fx_reset_counts(void);

/* Acknowledges every accepted publish that was not acknowledged yet. */
void fx_acknowledge_all(void);

/* Moves the clock forward in 100 ms steps, calling lc_app_tick() at each. */
void fx_advance(uint32_t milliseconds);
/* The same, acknowledging every publish after each step. */
void fx_advance_acknowledging(uint32_t milliseconds);
