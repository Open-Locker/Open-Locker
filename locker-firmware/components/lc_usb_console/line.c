#include "lc_usb_console/line.h"

#include <string.h>

#include "lc_domain/compartment.h"

#define MAX_TOKENS 3
#define MAX_DIGITS 3

/*
 * One byte of UTF-8 checked against the sequence in progress (RFC 3629): no
 * overlong forms, no surrogates, nothing above U+10FFFF. False when the byte
 * does not belong there.
 */
static bool utf8_accepts(lc_console_line_t *input, uint8_t byte)
{
    if (input->utf8_remaining > 0) {
        if (byte < input->utf8_min || byte > input->utf8_max) {
            return false;
        }
        --input->utf8_remaining;
        input->utf8_min = 0x80;
        input->utf8_max = 0xBF;
        return true;
    }
    if (byte < 0x80) {
        return true;
    }
    input->utf8_min = 0x80;
    input->utf8_max = 0xBF;
    if (byte >= 0xC2 && byte <= 0xDF) {
        input->utf8_remaining = 1;
    } else if (byte >= 0xE0 && byte <= 0xEF) {
        input->utf8_remaining = 2;
        if (byte == 0xE0) {
            input->utf8_min = 0xA0;
        } else if (byte == 0xED) {
            input->utf8_max = 0x9F;
        }
    } else if (byte >= 0xF0 && byte <= 0xF4) {
        input->utf8_remaining = 3;
        if (byte == 0xF0) {
            input->utf8_min = 0x90;
        } else if (byte == 0xF4) {
            input->utf8_max = 0x8F;
        }
    } else {
        /* A continuation byte with no lead, C0, C1, and F5 to FF. */
        return false;
    }
    return true;
}

lc_console_feed_t lc_console_line_feed(lc_console_line_t *input, char byte)
{
    /* The LF of a CRLF: the CR already ended the line. */
    bool after_cr = input->after_cr;
    input->after_cr = byte == '\r';
    if (byte == '\n' && after_cr) {
        return LC_CONSOLE_LINE_PENDING;
    }
    if (input->finished) {
        input->finished = false;
        input->discarding = false;
        input->length = 0;
        input->utf8_remaining = 0;
    }

    if (byte == '\n' || byte == '\r') {
        input->finished = true;
        /* A sequence cut short by the newline is invalid too. */
        if (input->discarding || input->utf8_remaining > 0) {
            input->length = 0;
            input->line[0] = '\0';
            return LC_CONSOLE_LINE_REJECTED;
        }
        input->line[input->length] = '\0';
        return LC_CONSOLE_LINE_READY;
    }

    /* A NUL would end the string early and hide the rest of the line. */
    if (byte == '\0' || input->length == LC_CONSOLE_LINE_MAX ||
        !utf8_accepts(input, (uint8_t)byte)) {
        input->discarding = true;
    }
    if (!input->discarding) {
        input->line[input->length++] = byte;
    }
    return LC_CONSOLE_LINE_PENDING;
}

void lc_console_line_idle(lc_console_line_t *input, uint32_t idle_ms)
{
    /* What follows is the tail of a line nobody finished, not a command. */
    if (!input->finished && !input->discarding && input->length > 0 &&
        idle_ms >= LC_CONSOLE_IDLE_TIMEOUT_MS) {
        input->discarding = true;
        input->length = 0;
        input->line[0] = '\0';
    }
}

typedef struct {
    const char *start;
    size_t length;
} token_t;

/* Splits on spaces. Returns the token count, or MAX_TOKENS + 1 when there are more. */
static size_t tokenize(const char *line, token_t tokens[MAX_TOKENS])
{
    size_t count = 0;
    const char *cursor = line;

    for (;;) {
        while (*cursor == ' ') {
            ++cursor;
        }
        if (*cursor == '\0') {
            return count;
        }
        if (count == MAX_TOKENS) {
            return MAX_TOKENS + 1;
        }
        tokens[count].start = cursor;
        while (*cursor != ' ' && *cursor != '\0') {
            ++cursor;
        }
        tokens[count].length = (size_t)(cursor - tokens[count].start);
        ++count;
    }
}

static bool token_is(token_t token, const char *word)
{
    return token.length == strlen(word) && memcmp(token.start, word, token.length) == 0;
}

/* Plain decimal digits only, within `min..max`. */
static bool parse_number(token_t token, unsigned min, unsigned max, uint8_t *out)
{
    unsigned value = 0;

    if (token.length == 0 || token.length > MAX_DIGITS) {
        return false;
    }
    for (size_t i = 0; i < token.length; ++i) {
        if (token.start[i] < '0' || token.start[i] > '9') {
            return false;
        }
        value = value * 10 + (unsigned)(token.start[i] - '0');
    }
    if (value < min || value > max) {
        return false;
    }
    *out = (uint8_t)value;
    return true;
}

static bool parse_board(token_t token, uint8_t *out)
{
    return parse_number(token, LC_BOARD_ADDRESS_MIN, LC_BOARD_ADDRESS_MAX, out);
}

/* The text after the first word, or NULL when there is none. */
static const char *rest_of_line(const char *line)
{
    while (*line == ' ') {
        ++line;
    }
    while (*line != ' ' && *line != '\0') {
        ++line;
    }
    while (*line == ' ') {
        ++line;
    }
    return *line == '\0' ? NULL : line;
}

lc_console_command_t lc_console_parse(const char *line)
{
    lc_console_command_t command = {.kind = LC_CONSOLE_INVALID};
    token_t tokens[MAX_TOKENS];
    size_t count = tokenize(line, tokens);

    /* Commands that carry a JSON document take the rest of the line, spaces and all. */
    if (count >= 2 && (token_is(tokens[0], "command") || token_is(tokens[0], "configure"))) {
        command.argument = rest_of_line(line);
        command.kind = command.argument == NULL ? LC_CONSOLE_INVALID
                       : token_is(tokens[0], "command") ? LC_CONSOLE_COMMAND
                                                        : LC_CONSOLE_CONFIGURE;
        return command;
    }

    if (count == 0 || count > MAX_TOKENS) {
        return command;
    }

    if (count == 1 && token_is(tokens[0], "status")) {
        command.kind = LC_CONSOLE_STATUS;
    } else if (count == 1 && token_is(tokens[0], "diagnostics")) {
        command.kind = LC_CONSOLE_DIAGNOSTICS;
    } else if (count == 1 && token_is(tokens[0], "self-test")) {
        command.kind = LC_CONSOLE_SELF_TEST;
    } else if (count == 1 && token_is(tokens[0], "reset-network")) {
        command.kind = LC_CONSOLE_RESET_NETWORK;
    } else if (count == 3 && token_is(tokens[0], "unlock")) {
        if (parse_board(tokens[1], &command.board) &&
            parse_number(tokens[2], 0, LC_COMPARTMENT_ADDRESS_MAX, &command.address)) {
            command.kind = LC_CONSOLE_UNLOCK;
        }
    } else if (count == 2 && token_is(tokens[0], "query")) {
        if (parse_board(tokens[1], &command.board)) {
            command.kind = LC_CONSOLE_QUERY;
        }
    }
    return command;
}
