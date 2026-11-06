#include "lc_usb_console/console.h"

#include <stdio.h>
#include <string.h>

#include "lc_usb_console/line.h"
#include "lc_usb_console/setup_json.h"

#define REPLY_MAX 96

static const char *bus_state_name(lc_bus_state_t state)
{
    switch (state) {
    case LC_BUS_CONNECTED:
        return "connected";
    case LC_BUS_UNREACHABLE:
        return "unreachable";
    case LC_BUS_DISCONNECTED:
    default:
        return "disconnected";
    }
}

static const char *unlock_result_name(lc_unlock_result_t result)
{
    switch (result) {
    case LC_UNLOCK_ACKNOWLEDGED:
        return "ok unlock acknowledged\n";
    case LC_UNLOCK_NOT_SENT:
        return "error unlock not_sent\n";
    case LC_UNLOCK_REFUSED:
        return "error unlock refused\n";
    case LC_UNLOCK_OUTCOME_UNKNOWN:
    default:
        return "error unlock outcome_unknown\n";
    }
}

static void reply(const lc_console_t *console, const char *text)
{
    console->write(console->ctx, text);
}

static void status(const lc_console_t *console)
{
    char text[REPLY_MAX];
    bool enrolled = false;
    bool pending = false;
    bool configured = false;
    int length = snprintf(text, sizeof(text), "ok bus=%s",
                          bus_state_name(console->bus.connection_state(console->bus.ctx)));

    /* What the installer's tool needs to know before it offers to send a token. */
    if (console->provisioning_state != NULL &&
        console->provisioning_state(console->ctx, &enrolled, &pending, &configured)) {
        snprintf(text + length, sizeof(text) - (size_t)length,
                 " enrolled=%d pending=%d configured=%d", enrolled, pending, configured);
    }
    length = (int)strlen(text);
    text[length] = '\n';
    text[length + 1] = '\0';
    reply(console, text);
}

static void diagnostics(const lc_console_t *console)
{
    char text[REPLY_MAX];

    snprintf(text, sizeof(text), "ok bus=%s unlock_transmissions=%lu\n",
             bus_state_name(console->bus.connection_state(console->bus.ctx)),
             (unsigned long)console->unlock_transmissions(console->ctx));
    reply(console, text);
}

/*
 * The bench `unlock` skips the journal, so it exists only until the device is
 * set up. Once it is enrolled or has network settings, commands come from the
 * broker alone.
 */
static bool bench_commands_allowed(const lc_console_t *console)
{
    bool enrolled = false;
    bool pending = false;
    bool configured = false;

    return console->provisioning_state == NULL ||
           (console->provisioning_state(console->ctx, &enrolled, &pending, &configured) &&
            !enrolled && !configured);
}

static void unlock(const lc_console_t *console, uint8_t board, uint8_t address)
{
    /* A bench command addresses the board directly; no compartment is mapped. */
    lc_target_t target = {.compartment_number = 0, .board = board, .address = address};

    lc_unlock_observation_t observation = console->bus.unlock_compartment(console->bus.ctx, target);

    reply(console, unlock_result_name(observation.result));
}

/* One character per address from 0: c closed, o open, ? unknown. */
static void query(const lc_console_t *console, uint8_t board)
{
    uint8_t addresses[LC_CONSOLE_QUERY_ADDRESSES];
    lc_door_t doors[LC_CONSOLE_QUERY_ADDRESSES];
    char text[REPLY_MAX];

    for (unsigned i = 0; i < LC_CONSOLE_QUERY_ADDRESSES; ++i) {
        addresses[i] = (uint8_t)i;
    }
    if (!console->bus.read_compartment_states(console->bus.ctx, board, addresses,
                                              LC_CONSOLE_QUERY_ADDRESSES, doors)) {
        reply(console, "error query no_valid_reply\n");
        return;
    }

    int length = snprintf(text, sizeof(text), "ok doors=");
    for (unsigned i = 0; i < LC_CONSOLE_QUERY_ADDRESSES; ++i) {
        text[length++] = doors[i] == LC_DOOR_CLOSED ? 'c' : doors[i] == LC_DOOR_OPEN ? 'o' : '?';
    }
    text[length++] = '\n';
    text[length] = '\0';
    reply(console, text);
}

/*
 * Silent on success: the outcome of the settings is the one reply to this
 * line, and it comes from the application. Nothing submitted is ever echoed.
 */
static void configure(const lc_console_t *console, const char *json)
{
    lc_setup_request_t request;

    if (!lc_console_parse_setup(json, &request)) {
        reply(console, "error invalid command\n");
    } else if (console->submit_setup == NULL || !console->submit_setup(console->ctx, &request)) {
        reply(console, "error setup not accepted\n");
    }
    /* The submitted values do not stay on the stack longer than needed. */
    memset(&request, 0, sizeof(request));
}

void lc_console_execute(const lc_console_t *console, const char *line)
{
    lc_console_command_t command = lc_console_parse(line);

    switch (command.kind) {
    case LC_CONSOLE_STATUS:
        status(console);
        break;
    case LC_CONSOLE_DIAGNOSTICS:
        diagnostics(console);
        break;
    case LC_CONSOLE_SELF_TEST:
        reply(console, console->self_test(console->ctx) ? "ok self-test passed\n"
                                                        : "error self-test failed\n");
        break;
    case LC_CONSOLE_UNLOCK:
        if (bench_commands_allowed(console)) {
            unlock(console, command.board, command.address);
        } else {
            reply(console, "error unlock disabled\n");
        }
        break;
    case LC_CONSOLE_QUERY:
        query(console, command.board);
        break;
    case LC_CONSOLE_COMMAND:
        /* The payload is never repeated in the reply. */
        reply(console, console->submit_command != NULL &&
                               console->submit_command(console->ctx, command.argument)
                           ? "ok command queued\n"
                           : "error command not accepted\n");
        break;
    case LC_CONSOLE_CONFIGURE:
        configure(console, command.argument);
        break;
    case LC_CONSOLE_RESET_NETWORK:
        reply(console, console->request_reset != NULL && console->request_reset(console->ctx)
                           ? "ok reset requested\n"
                           : "error reset not accepted\n");
        break;
    case LC_CONSOLE_INVALID:
    default:
        reply(console, "error invalid command\n");
        break;
    }
}

void lc_console_reject(const lc_console_t *console)
{
    reply(console, "error line too long\n");
}

size_t lc_console_feed_bytes(const lc_console_t *console, lc_console_line_t *input,
                             const uint8_t *bytes, size_t length)
{
    size_t taken = length < LC_CONSOLE_BYTES_PER_PASS ? length : LC_CONSOLE_BYTES_PER_PASS;

    for (size_t i = 0; i < taken; ++i) {
        switch (lc_console_line_feed(input, (char)bytes[i])) {
        case LC_CONSOLE_LINE_READY:
            lc_console_execute(console, input->line);
            break;
        case LC_CONSOLE_LINE_REJECTED:
            lc_console_reject(console);
            break;
        case LC_CONSOLE_LINE_PENDING:
        default:
            break;
        }
    }
    return taken;
}
