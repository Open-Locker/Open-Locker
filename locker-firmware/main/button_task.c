#include <string.h>

#include "driver/gpio.h"
#include "esp_log.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "lc_button/button.h"
#include "lc_button/pin.h"
#include "runtime.h"
#include "sdkconfig.h"

/*
 * The reset button: samples the pin every 20 ms and feeds lc_button. The
 * decisions are in that component; this file reads the pin and posts the reset
 * to the owner task. A reset the owner task refused (full queue) is offered
 * again on the following samples for about 2 s, then reported.
 */

#ifndef CONFIG_ESP_CONSOLE_UART_TX_GPIO
#define CONFIG_ESP_CONSOLE_UART_TX_GPIO 1
#endif
#ifndef CONFIG_ESP_CONSOLE_UART_RX_GPIO
#define CONFIG_ESP_CONSOLE_UART_RX_GPIO 3
#endif

_Static_assert(!LC_BUTTON_PIN_REJECTED(CONFIG_LC_RESET_BUTTON_GPIO, CONFIG_LC_RS485_TX_PIN,
                                       CONFIG_LC_RS485_RX_PIN, CONFIG_ESP_CONSOLE_UART_TX_GPIO,
                                       CONFIG_ESP_CONSOLE_UART_RX_GPIO),
               "CONFIG_LC_RESET_BUTTON_GPIO is a strapping, flash, input-only, console or RS485 pin");

#define SAMPLE_PERIOD_MS 20
#define STACK_SIZE 3072
#define PRIORITY 3

static const char *TAG = "button";

static lc_button_t button;

static int64_t now_ms(void)
{
    return esp_timer_get_time() / 1000;
}

static void button_task(void *arg)
{
    (void)arg;
    TickType_t last_wake = xTaskGetTickCount();

    for (;;) {
        xTaskDelayUntil(&last_wake, pdMS_TO_TICKS(SAMPLE_PERIOD_MS));
        switch (lc_button_sample(&button, now_ms(), gpio_get_level(CONFIG_LC_RESET_BUTTON_GPIO) != 0)) {
        case LC_BUTTON_POST_RESET: {
            owner_event_t event;
            memset(&event, 0, sizeof(event));
            event.kind = OWNER_RESET;
            lc_button_post_result(&button, owner_post(&event));
            break;
        }
        case LC_BUTTON_RESET_ABANDONED:
            ESP_LOGE(TAG, "the reset gesture was done but the reset could not be posted");
            break;
        case LC_BUTTON_NONE:
            break;
        }
    }
}

bool button_task_start(void)
{
    const gpio_config_t pin = {
        .pin_bit_mask = 1ULL << CONFIG_LC_RESET_BUTTON_GPIO,
        .mode = GPIO_MODE_INPUT,
        .pull_up_en = GPIO_PULLUP_ENABLE,
        .pull_down_en = GPIO_PULLDOWN_DISABLE,
        .intr_type = GPIO_INTR_DISABLE,
    };

    lc_button_init(&button);
    return gpio_config(&pin) == ESP_OK &&
           xTaskCreate(button_task, "button", STACK_SIZE, NULL, PRIORITY, NULL) == pdPASS;
}
