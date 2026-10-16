#include "lc_rs485_lock_board/bus.h"

#include <string.h>

#include "lc_rs485_lock_board/codec.h"

typedef enum {
    /* Nothing was written. */
    EXCHANGE_NOT_SENT,
    /* At least one byte was written, but no usable reply frame was received. */
    EXCHANGE_NO_REPLY,
    /* One complete frame was received in answer to a fully written request. */
    EXCHANGE_REPLIED,
} exchange_t;

static int64_t now_ms(const lc_rs485_bus_t *bus)
{
    return bus->clock.now_ms(bus->clock.ctx);
}

static int read_serial(lc_rs485_bus_t *bus, uint8_t *buffer, size_t capacity, int64_t timeout_ms)
{
    return bus->serial.read(bus->serial.ctx, buffer, capacity, (uint32_t)timeout_ms);
}

static void record_failure(lc_rs485_bus_t *bus, bool transmitted)
{
    int64_t now = now_ms(bus);

    if (transmitted) {
        bus->settle_until_ms = now + bus->config.settle_period_ms;
    }
    if (bus->failures < UINT8_MAX) {
        ++bus->failures;
    }
    if (bus->config.failures_before_cooldown > 0 &&
        bus->failures >= bus->config.failures_before_cooldown) {
        bus->state = LC_BUS_UNREACHABLE;
        bus->cooldown_until_ms = now + bus->config.cooldown_ms;
    } else {
        bus->state = LC_BUS_DISCONNECTED;
    }
}

static void record_success(lc_rs485_bus_t *bus)
{
    bus->failures = 0;
    bus->state = LC_BUS_CONNECTED;
}

/* Discards everything received until `until_ms`. False when the line cannot be read. */
static bool discard_until(lc_rs485_bus_t *bus, int64_t until_ms)
{
    uint8_t discarded[LC_RS485_FRAME_MAX];

    for (int64_t now = now_ms(bus); now < until_ms; now = now_ms(bus)) {
        if (read_serial(bus, discarded, sizeof(discarded), until_ms - now) < 0) {
            return false;
        }
    }
    return true;
}

/*
 * Discards what is in the receive buffer, until the line has been quiet. False
 * when it cannot be read, or is still not quiet after one reply deadline.
 */
static bool drain(lc_rs485_bus_t *bus)
{
    uint8_t discarded[LC_RS485_FRAME_MAX];
    int64_t deadline_ms = now_ms(bus) + bus->config.reply_deadline_ms;

    for (;;) {
        int received = read_serial(bus, discarded, sizeof(discarded), bus->config.quiet_interval_ms);
        if (received <= 0) {
            return received == 0;
        }
        if (now_ms(bus) >= deadline_ms) {
            return false;
        }
    }
}

/*
 * Receives one frame: the bytes from the first one up to a quiet interval.
 * False when none arrived before the deadline, the frame was still running at
 * the deadline, it did not fit, or the line could not be read.
 */
static bool receive_frame(lc_rs485_bus_t *bus, int64_t deadline_ms, uint8_t *frame,
                          size_t *length)
{
    uint8_t overflow[LC_RS485_FRAME_MAX];
    bool overflowed = false;
    int64_t wait_ms = deadline_ms - now_ms(bus);

    *length = 0;
    for (;;) {
        bool full = *length == LC_RS485_FRAME_MAX;
        int received = full ? read_serial(bus, overflow, sizeof(overflow), wait_ms)
                            : read_serial(bus, frame + *length, LC_RS485_FRAME_MAX - *length,
                                          wait_ms);
        if (received < 0) {
            return false;
        }
        if (received == 0) {
            /* Quiet, unless it was the deadline that cut the wait short. */
            return *length > 0 && !overflowed && wait_ms == bus->config.quiet_interval_ms;
        }
        if (full) {
            overflowed = true;
        } else {
            *length += (size_t)received;
        }

        int64_t remaining_ms = deadline_ms - now_ms(bus);
        if (remaining_ms <= 0) {
            return false;
        }
        wait_ms = remaining_ms < bus->config.quiet_interval_ms ? remaining_ms
                                                               : bus->config.quiet_interval_ms;
    }
}

/* One request, written exactly once, and at most one reply frame. Never retried. */
static exchange_t exchange(lc_rs485_bus_t *bus, const uint8_t request[LC_RS485_REQUEST_SIZE],
                           uint32_t *transmissions, uint8_t *frame, size_t *length)
{
    if (bus->state == LC_BUS_UNREACHABLE && now_ms(bus) < bus->cooldown_until_ms) {
        return EXCHANGE_NOT_SENT;
    }
    /* A late reply to a timed-out request is discarded here, with the rest. */
    if (!discard_until(bus, bus->settle_until_ms) || !drain(bus)) {
        record_failure(bus, false);
        return EXCHANGE_NOT_SENT;
    }

    int written = bus->serial.write(bus->serial.ctx, request, LC_RS485_REQUEST_SIZE);
    if (written <= 0) {
        record_failure(bus, false);
        return EXCHANGE_NOT_SENT;
    }
    if (transmissions != NULL) {
        ++*transmissions;
    }
    if (written < LC_RS485_REQUEST_SIZE) {
        record_failure(bus, true);
        return EXCHANGE_NO_REPLY;
    }

    int64_t deadline_ms = now_ms(bus) + bus->config.reply_deadline_ms;
    /* write() returns once the last bit is out, so a fast reply may already be waiting. */
    if (!receive_frame(bus, deadline_ms, frame, length)) {
        record_failure(bus, true);
        return EXCHANGE_NO_REPLY;
    }
    return EXCHANGE_REPLIED;
}

static bool apply_profile(void *ctx, const lc_hardware_profile_t *profile)
{
    lc_rs485_bus_t *bus = ctx;

    if (profile == NULL || (profile->feedback != LC_FEEDBACK_DOOR_CLOSING &&
                            profile->feedback != LC_FEEDBACK_DOOR_OPENING)) {
        return false;
    }
    bus->feedback = profile->feedback;
    return true;
}

static lc_unlock_observation_t unlock_compartment(void *ctx, lc_target_t target)
{
    lc_rs485_bus_t *bus = ctx;
    lc_unlock_observation_t observation = {.result = LC_UNLOCK_NOT_SENT,
                                           .observed = LC_DOOR_UNKNOWN};
    uint8_t request[LC_RS485_REQUEST_SIZE];
    uint8_t frame[LC_RS485_FRAME_MAX];
    size_t length = 0;

    if (!lc_rs485_encode_unlock(target.board, target.address, request)) {
        return observation;
    }

    switch (exchange(bus, request, &bus->unlock_transmissions, frame, &length)) {
    case EXCHANGE_NOT_SENT:
        break;
    case EXCHANGE_NO_REPLY:
        observation.result = LC_UNLOCK_OUTCOME_UNKNOWN;
        break;
    case EXCHANGE_REPLIED:
        if (lc_rs485_decode_unlock_ack(frame, length, target.board, target.address)) {
            record_success(bus);
            observation.result = LC_UNLOCK_ACKNOWLEDGED;
        } else {
            record_failure(bus, true);
            observation.result = LC_UNLOCK_OUTCOME_UNKNOWN;
        }
        break;
    }
    return observation;
}

static bool read_compartment_states(void *ctx, uint8_t board, const uint8_t *addresses,
                                    size_t count, lc_door_t *out)
{
    lc_rs485_bus_t *bus = ctx;
    lc_door_t states[LC_RS485_ADDRESS_COUNT];
    uint8_t request[LC_RS485_REQUEST_SIZE];
    uint8_t frame[LC_RS485_FRAME_MAX];
    size_t length = 0;

    for (size_t i = 0; i < count; ++i) {
        out[i] = LC_DOOR_UNKNOWN;
    }
    if (!lc_rs485_encode_query_all(board, request)) {
        return false;
    }
    if (exchange(bus, request, NULL, frame, &length) != EXCHANGE_REPLIED) {
        return false;
    }
    if (!lc_rs485_decode_query_all(frame, length, board, bus->feedback, states)) {
        record_failure(bus, true);
        return false;
    }

    record_success(bus);
    for (size_t i = 0; i < count; ++i) {
        if (addresses[i] < LC_RS485_ADDRESS_COUNT) {
            out[i] = states[addresses[i]];
        }
    }
    return true;
}

static lc_bus_state_t connection_state(void *ctx)
{
    const lc_rs485_bus_t *bus = ctx;
    return bus->state;
}

void lc_rs485_bus_init(lc_rs485_bus_t *bus, lc_rs485_serial_t serial, lc_clock_port_t clock,
                       const lc_rs485_bus_config_t *config)
{
    memset(bus, 0, sizeof(*bus));
    bus->serial = serial;
    bus->clock = clock;
    bus->config = *config;
    bus->feedback = LC_FEEDBACK_DOOR_CLOSING;
    bus->state = LC_BUS_DISCONNECTED;
}

lc_locker_bus_port_t lc_rs485_bus_port(lc_rs485_bus_t *bus)
{
    return (lc_locker_bus_port_t){
        .ctx = bus,
        .apply_profile = apply_profile,
        .unlock_compartment = unlock_compartment,
        .read_compartment_states = read_compartment_states,
        .connection_state = connection_state,
    };
}

uint32_t lc_rs485_bus_unlock_transmissions(const lc_rs485_bus_t *bus)
{
    return bus->unlock_transmissions;
}
