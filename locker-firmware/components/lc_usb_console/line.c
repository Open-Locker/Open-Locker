#include "lc_usb_console/line.h"

#include <string.h>

#include "lc_domain/compartment.h"

#define MAX_TOKENS 3
#define MAX_DIGITS 3

lc_console_feed_t lc_console_line_feed(lc_console_line_t *input, char byte)
{
    if (byte == '\r') {
        return LC_CONSOLE_LINE_PENDING;
    }
    if (input->finished) {
        input->finished = false;
        input->discarding = false;
        input->length = 0;
    }

    if (byte == '\n') {
        input->finished = true;
        if (input->discarding) {
            input->length = 0;
            input->line[0] = '\0';
            return LC_CONSOLE_LINE_REJECTED;
        }
        input->line[input->length] = '\0';
        return LC_CONSOLE_LINE_READY;
    }

    /* A NUL would end the string early and hide the rest of the line. */
    if (byte == '\0' || input->length == LC_CONSOLE_LINE_MAX) {
        input->discarding = true;
    }
    if (!input->discarding) {
        input->line[input->length++] = byte;
    }
    return LC_CONSOLE_LINE_PENDING;
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

lc_console_command_t lc_console_parse(const char *line)
{
    lc_console_command_t command = {.kind = LC_CONSOLE_INVALID};
    token_t tokens[MAX_TOKENS];
    size_t count = tokenize(line, tokens);

    if (count == 0 || count > MAX_TOKENS) {
        return command;
    }

    if (count == 1 && token_is(tokens[0], "status")) {
        command.kind = LC_CONSOLE_STATUS;
    } else if (count == 1 && token_is(tokens[0], "diagnostics")) {
        command.kind = LC_CONSOLE_DIAGNOSTICS;
    } else if (count == 1 && token_is(tokens[0], "self-test")) {
        command.kind = LC_CONSOLE_SELF_TEST;
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
