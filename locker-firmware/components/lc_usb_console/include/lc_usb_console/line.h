#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/* Line assembly and command parsing for the USB console. Pure: no I/O. */

/* Long enough for a command JSON of a few compartments, not for a whole bank. */
#define LC_CONSOLE_LINE_MAX 1024
/* A partial line with no new byte for this long is abandoned. */
#define LC_CONSOLE_IDLE_TIMEOUT_MS 10000

typedef struct {
    char line[LC_CONSOLE_LINE_MAX + 1];
    size_t length;
    bool discarding;
    bool finished;
    /* The last byte was a CR, so an LF now is the second half of a CRLF. */
    bool after_cr;
    /* Continuation bytes still owed to the UTF-8 sequence in progress. */
    uint8_t utf8_remaining;
    /* The range the next byte of that sequence has to be in. */
    uint8_t utf8_min;
    uint8_t utf8_max;
} lc_console_line_t;

typedef enum {
    LC_CONSOLE_LINE_PENDING = 0,
    /* A newline ended a line that fit. `line` holds it, NUL-terminated. */
    LC_CONSOLE_LINE_READY,
    /*
     * A newline ended a line that was too long, held a NUL or invalid UTF-8, or
     * was abandoned while partial. Nothing of it is kept.
     */
    LC_CONSOLE_LINE_REJECTED,
} lc_console_feed_t;

/*
 * Feeds one received byte. Start from a zeroed struct. A CR, an LF or a CRLF
 * ends a line (ESP-IDF's monitor sends a bare CR). After READY or REJECTED the
 * next byte starts a new line. A line is ASCII or well-formed UTF-8 (a network
 * name may not be ASCII).
 */
lc_console_feed_t lc_console_line_feed(lc_console_line_t *input, char byte);

/*
 * Reports how long no byte has arrived. At LC_CONSOLE_IDLE_TIMEOUT_MS a partial
 * line is discarded through its newline, like a line that was too long.
 */
void lc_console_line_idle(lc_console_line_t *input, uint32_t idle_ms);

typedef enum {
    LC_CONSOLE_INVALID = 0,
    LC_CONSOLE_STATUS,
    LC_CONSOLE_DIAGNOSTICS,
    LC_CONSOLE_SELF_TEST,
    /* `unlock <board> <address>`: board 1..31, zero-based address 0..254. */
    LC_CONSOLE_UNLOCK,
    /* `query <board>`: board 1..31. */
    LC_CONSOLE_QUERY,
    /* `command <json>`: one MQTT command payload, as the broker would deliver it. */
    LC_CONSOLE_COMMAND,
    /* `configure <json>`: network settings and, before enrollment, the token. */
    LC_CONSOLE_CONFIGURE,
    /* `reset-network`: forget network settings, identity and mapping. */
    LC_CONSOLE_RESET_NETWORK,
} lc_console_kind_t;

typedef struct {
    lc_console_kind_t kind;
    uint8_t board;
    uint8_t address;
    /* LC_CONSOLE_COMMAND, LC_CONSOLE_CONFIGURE: the text after the command word. */
    const char *argument;
} lc_console_command_t;

/* Anything that is not exactly one known command with valid arguments is INVALID. */
lc_console_command_t lc_console_parse(const char *line);
