#include <stdio.h>
#include <string.h>

#include "console_glue.h"
#include "esp_heap_caps.h"
#include "lc_mqtt_codec/codec.h"
#include "sdkconfig.h"

/*
 * The console's connections to the rest of the device. Called on the console
 * task; everything that changes state is posted to the owner task.
 */

static void write_line(void *ctx, const char *text)
{
    (void)ctx;
    fputs(text, stdout);
    fflush(stdout);
}

/*
 * `command <json>`: as if the broker had delivered it. Only on the bench, where
 * there is no broker; once the device has network settings, commands come from
 * the broker alone and the console is no second way to unlock.
 */
static bool submit_command(void *ctx, const char *json)
{
    (void)ctx;
    owner_event_t event;

    if (device_state.configured) {
        return false;
    }

    memset(&event, 0, sizeof(event));
    event.kind = OWNER_APP;
    event.u.app.kind = LC_EVENT_COMMAND;
    return lc_mqtt_decode_command(json, strlen(json), &event.u.app.command) && owner_post(&event);
}

static bool submit_setup(void *ctx, const lc_setup_request_t *request)
{
    (void)ctx;
    owner_event_t event;

    memset(&event, 0, sizeof(event));
    event.kind = OWNER_SETUP;
    event.channel = LC_COMMISSION_CHANNEL_CONSOLE;
    event.u.setup = *request;
    bool posted = owner_post(&event);
    /* The queue holds its own copy; this one held a token. */
    memset(&event, 0, sizeof(event));
    return posted;
}

static bool request_reset(void *ctx)
{
    (void)ctx;
    owner_event_t event;

    memset(&event, 0, sizeof(event));
    event.kind = OWNER_RESET;
    return owner_post(&event);
}

static bool provisioning_state(void *ctx, bool *enrolled, bool *pending, bool *configured)
{
    (void)ctx;
    *enrolled = device_state.enrolled;
    *pending = device_state.pending;
    *configured = device_state.configured;
    return true;
}

static bool memory(void *ctx, uint32_t *min_free_heap, uint32_t *owner_stack)
{
    (void)ctx;
    *min_free_heap = (uint32_t)heap_caps_get_minimum_free_size(MALLOC_CAP_8BIT);
    return owner_stack_free(owner_stack);
}

void console_glue_init(lc_console_t *console, const lc_locker_bus_port_t *bus,
                       uint32_t (*unlock_transmissions)(void *ctx),
                       bool (*self_test)(void *ctx))
{
    *console = (lc_console_t){
        .bus = *bus,
        .ctx = NULL,
        .write = write_line,
        .unlock_transmissions = unlock_transmissions,
        .memory = memory,
        .self_test = self_test,
        .submit_command = submit_command,
        .submit_setup = submit_setup,
        .request_reset = request_reset,
        .provisioning_state = provisioning_state,
#ifndef CONFIG_LC_BENCH_CONSOLE
        .production = true,
#endif
    };
}

static void (*portal_reply)(lc_commission_result_t result);

void console_glue_set_portal_reply(void (*reply)(lc_commission_result_t result))
{
    portal_reply = reply;
}

void console_glue_reply(void *ctx, lc_commission_channel_t channel, lc_commission_result_t result)
{
    (void)ctx;
    if (channel == LC_COMMISSION_CHANNEL_PORTAL) {
        if (portal_reply != NULL) {
            portal_reply(result);
        }
        return;
    }
    /* One line, one outcome, and never a value that was submitted. */
    switch (result) {
    case LC_COMMISSION_SAVED:
        write_line(NULL, "ok saved\n");
        break;
    case LC_COMMISSION_INVALID:
        write_line(NULL, "error invalid_settings\n");
        break;
    case LC_COMMISSION_RESET_REQUIRED:
        write_line(NULL, "error reset_required\n");
        break;
    case LC_COMMISSION_SAVE_FAILED:
    default:
        write_line(NULL, "error save_failed\n");
        break;
    }
}
