#include <stdint.h>

#include "driver/uart.h"
#include "esp_err.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "lc_usb_console/console.h"
#include "lc_usb_console/line.h"
#include "sdkconfig.h"

#define CONSOLE_UART ((uart_port_t)CONFIG_ESP_CONSOLE_UART_NUM)
/* A whole `configure` line arrives at once; the ring must hold what comes while a command runs. */
#define RX_BUFFER_SIZE 2048
#define POLL_MS 100
/* A status query decodes every address, and a command line is a kilobyte. */
#define TASK_STACK_SIZE 8192
#define TASK_PRIORITY 5

static void console_task(void *arg)
{
    const lc_console_t *console = arg;
    static lc_console_line_t input;
    uint32_t idle_ms = 0;

    for (;;) {
        uint8_t bytes[LC_CONSOLE_BYTES_PER_PASS];
        int length = uart_read_bytes(CONSOLE_UART, bytes, sizeof(bytes), pdMS_TO_TICKS(POLL_MS));

        if (length <= 0) {
            /* A partial line nobody finishes is abandoned, with its tail. */
            idle_ms += POLL_MS;
            lc_console_line_idle(&input, idle_ms);
            continue;
        }
        idle_ms = 0;
        for (size_t offset = 0; offset < (size_t)length;) {
            offset += lc_console_feed_bytes(console, &input, bytes + offset, (size_t)length - offset);
        }
    }
}

void lc_usb_console_start(const lc_console_t *console)
{
    ESP_ERROR_CHECK(uart_driver_install(CONSOLE_UART, RX_BUFFER_SIZE, 0, 0, NULL, 0));
    xTaskCreate(console_task, "lc_console", TASK_STACK_SIZE, (void *)console, TASK_PRIORITY, NULL);
}
