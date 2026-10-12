#include <stdint.h>

#include "driver/uart.h"
#include "esp_err.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "lc_usb_console/console.h"
#include "lc_usb_console/line.h"
#include "sdkconfig.h"

#define CONSOLE_UART ((uart_port_t)CONFIG_ESP_CONSOLE_UART_NUM)
#define RX_BUFFER_SIZE 256
/* A status query decodes every address on the stack of this task. */
#define TASK_STACK_SIZE 6144
#define TASK_PRIORITY 5

static void console_task(void *arg)
{
    const lc_console_t *console = arg;
    lc_console_line_t input = {0};

    for (;;) {
        uint8_t byte;
        if (uart_read_bytes(CONSOLE_UART, &byte, 1, portMAX_DELAY) != 1) {
            vTaskDelay(pdMS_TO_TICKS(10));
            continue;
        }
        switch (lc_console_line_feed(&input, (char)byte)) {
        case LC_CONSOLE_LINE_READY:
            lc_console_execute(console, input.line);
            break;
        case LC_CONSOLE_LINE_REJECTED:
            lc_console_reject(console);
            break;
        case LC_CONSOLE_LINE_PENDING:
        default:
            break;
        }
    }
}

void lc_usb_console_start(const lc_console_t *console)
{
    ESP_ERROR_CHECK(uart_driver_install(CONSOLE_UART, RX_BUFFER_SIZE, 0, 0, NULL, 0));
    xTaskCreate(console_task, "lc_console", TASK_STACK_SIZE, (void *)console, TASK_PRIORITY, NULL);
}
