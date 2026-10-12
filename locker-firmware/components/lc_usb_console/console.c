#include "lc_usb_console/console.h"

#include <stdio.h>

#include "lc_usb_console/line.h"

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

    snprintf(text, sizeof(text), "ok bus=%s\n",
             bus_state_name(console->bus.connection_state(console->bus.ctx)));
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
        unlock(console, command.board, command.address);
        break;
    case LC_CONSOLE_QUERY:
        query(console, command.board);
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
