#include <stdbool.h>
#include "driver/uart.h"
#include "esp_err.h"
#include "esp_log.h"
#include "freertos/FreeRTOS.h"
#include "lock_bus.h"

static const char *TAG = "panel_test";

static void query_status(void)
{
    uint8_t response[LOCK_BUS_MAX_FRAME_SIZE];
    size_t length = 0;
    const esp_err_t result = lock_bus_query_all(1, response, sizeof(response), &length);

    if (length > 0) {
        ESP_LOGI(TAG, "RX (%u bytes):", (unsigned)length);
        ESP_LOG_BUFFER_HEX_LEVEL(TAG, response, length, ESP_LOG_INFO);
    }
    if (result == ESP_OK) {
        ESP_LOGI(TAG, "Valid status response from board 1 (header, address, command, XOR)");
    } else if (result == ESP_ERR_TIMEOUT) {
        ESP_LOGW(TAG, "No reply: check panel power, DIP address 1, A/B and UART wiring");
    } else {
        ESP_LOGW(TAG, "Query failed: %s", esp_err_to_name(result));
    }
}

void app_main(void)
{
    ESP_LOGI(TAG, "RS485 manual test: TX=IO17, RX=IO16, 9600 8N1, board=1");
    ESP_ERROR_CHECK(lock_bus_init());
    // USB serial uses console UART0; the panel uses UART2.
    ESP_ERROR_CHECK(uart_driver_install(UART_NUM_0, 256, 0, 0, NULL, 0));
    ESP_LOGI(TAG, "Type o + Enter to unlock channel 1 once; s + Enter to query status");
    ESP_LOGI(TAG, "No commands are sent automatically or on startup");

    char command = 0;
    bool invalid_line = false;
    while (true) {
        uint8_t input;
        if (uart_read_bytes(UART_NUM_0, &input, 1, pdMS_TO_TICKS(20)) != 1) {
            continue;
        }
        if (input == '\r' || input == '\n') {
            if (!invalid_line && command == 'o') {
                ESP_LOGI(TAG, "TX unlock board 1, channel 1: 8A 01 01 11 9B (one attempt)");
                const esp_err_t result = lock_bus_unlock(1, 1);
                if (result == ESP_OK) {
                    ESP_LOGI(TAG, "Unlock transmitted. Check the physical lock; no retry sent");
                } else {
                    ESP_LOGW(TAG, "Transmit failed: %s; no retry sent", esp_err_to_name(result));
                }
            } else if (!invalid_line && command == 's') {
                query_status();
            } else if (invalid_line || command != 0) {
                ESP_LOGW(TAG, "Use exactly o + Enter or s + Enter");
            }
            command = 0;
            invalid_line = false;
        } else if (command == 0 && !invalid_line) {
            command = (char)input;
        } else {
            // Reject multi-character input, rather than issuing repeated pulses.
            invalid_line = true;
        }
    }
}
