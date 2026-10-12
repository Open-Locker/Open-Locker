#include <stdio.h>

#include "esp_log.h"
#include "esp_timer.h"
#include "lc_rs485_lock_board/bus.h"
#include "lc_rs485_lock_board/codec.h"
#include "lc_serial_uart/serial_uart.h"
#include "lc_usb_console/console.h"
#include "sdkconfig.h"

/*
 * Composition root: the only place that constructs adapters and wires them to
 * ports. Nothing here writes to the RS485 line; the first request is sent
 * only when a console command asks for one.
 */

_Static_assert(CONFIG_LC_RS485_UART_NUM != CONFIG_ESP_CONSOLE_UART_NUM,
               "the RS485 line and the USB console need different UARTs");

#if CONFIG_LC_RS485_PARITY_EVEN
#define RS485_PARITY LC_SERIAL_PARITY_EVEN
#elif CONFIG_LC_RS485_PARITY_ODD
#define RS485_PARITY LC_SERIAL_PARITY_ODD
#else
#define RS485_PARITY LC_SERIAL_PARITY_NONE
#endif

static const char *TAG = "app_main";

static lc_serial_uart_t rs485_uart;
static lc_rs485_bus_t rs485_bus;
static lc_console_t console;

static int64_t monotonic_ms(void *ctx)
{
    (void)ctx;
    return esp_timer_get_time() / 1000;
}

static void console_write(void *ctx, const char *text)
{
    (void)ctx;
    fputs(text, stdout);
    fflush(stdout);
}

static uint32_t console_unlock_transmissions(void *ctx)
{
    (void)ctx;
    return lc_rs485_bus_unlock_transmissions(&rs485_bus);
}

static bool console_self_test(void *ctx)
{
    (void)ctx;
    return lc_rs485_codec_self_test();
}

void app_main(void)
{
    const lc_serial_uart_config_t uart_config = {
        .uart_num = CONFIG_LC_RS485_UART_NUM,
        .tx_pin = CONFIG_LC_RS485_TX_PIN,
        .rx_pin = CONFIG_LC_RS485_RX_PIN,
        .baud_rate = CONFIG_LC_RS485_BAUD_RATE,
        .parity = RS485_PARITY,
        .stop_bits = CONFIG_LC_RS485_STOP_BITS,
    };
    const lc_rs485_bus_config_t bus_config = {
        .reply_deadline_ms = CONFIG_LC_RS485_REPLY_DEADLINE_MS,
        .quiet_interval_ms = CONFIG_LC_RS485_QUIET_INTERVAL_MS,
        .settle_period_ms = CONFIG_LC_RS485_SETTLE_PERIOD_MS,
        .failures_before_cooldown = CONFIG_LC_RS485_FAILURES_BEFORE_COOLDOWN,
        .cooldown_ms = CONFIG_LC_RS485_COOLDOWN_MS,
    };

    /* With a failed UART every request is reported as not sent. */
    if (!lc_serial_uart_init(&rs485_uart, &uart_config)) {
        ESP_LOGE(TAG, "RS485 line unavailable");
    }

    lc_rs485_bus_init(&rs485_bus,
                      (lc_rs485_serial_t){.ctx = &rs485_uart,
                                          .write = lc_serial_uart_write,
                                          .read = lc_serial_uart_read},
                      (lc_clock_port_t){.ctx = NULL, .now_ms = monotonic_ms}, &bus_config);

    console = (lc_console_t){
        .bus = lc_rs485_bus_port(&rs485_bus),
        .ctx = NULL,
        .write = console_write,
        .unlock_transmissions = console_unlock_transmissions,
        .self_test = console_self_test,
    };
    lc_usb_console_start(&console);
}
