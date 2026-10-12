#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/* Line assembly and command parsing for the USB console. Pure: no I/O. */

#define LC_CONSOLE_LINE_MAX 128

typedef struct {
    char line[LC_CONSOLE_LINE_MAX + 1];
    size_t length;
    bool discarding;
    bool finished;
} lc_console_line_t;

typedef enum {
    LC_CONSOLE_LINE_PENDING = 0,
    /* A newline ended a line that fit. `line` holds it, NUL-terminated. */
    LC_CONSOLE_LINE_READY,
    /* A newline ended a line that was too long. Nothing of it is kept. */
    LC_CONSOLE_LINE_REJECTED,
} lc_console_feed_t;

/*
 * Feeds one received byte. Start from a zeroed struct. A carriage return is
 * ignored. After READY or REJECTED the next byte starts a new line.
 */
lc_console_feed_t lc_console_line_feed(lc_console_line_t *input, char byte);

typedef enum {
    LC_CONSOLE_INVALID = 0,
    LC_CONSOLE_STATUS,
    LC_CONSOLE_DIAGNOSTICS,
    LC_CONSOLE_SELF_TEST,
    /* `unlock <board> <address>`: board 1..31, zero-based address 0..254. */
    LC_CONSOLE_UNLOCK,
    /* `query <board>`: board 1..31. */
    LC_CONSOLE_QUERY,
} lc_console_kind_t;

typedef struct {
    lc_console_kind_t kind;
    uint8_t board;
    uint8_t address;
} lc_console_command_t;

/* Anything that is not exactly one known command with valid arguments is INVALID. */
lc_console_command_t lc_console_parse(const char *line);
