#include "lock_bus.h"

#include "driver/uart.h"
#include "freertos/FreeRTOS.h"

static const uart_port_t PORT = UART_NUM_2;

esp_err_t lock_bus_unlock(uint8_t board_address, uint8_t channel)
{
    if (board_address < 1 || board_address > 31 || channel == 0) {
        return ESP_ERR_INVALID_ARG;
    }
    const uint8_t request[] = {
        0x8a, board_address, channel, 0x11,
        (uint8_t)(0x8a ^ board_address ^ channel ^ 0x11),
    };
    if (uart_write_bytes(PORT, request, sizeof(request)) != (int)sizeof(request)) {
        return ESP_FAIL;
    }
    return uart_wait_tx_done(PORT, pdMS_TO_TICKS(100));
}

esp_err_t lock_bus_init(void)
{
    const uart_config_t config = {
        .baud_rate = 9600,
        .data_bits = UART_DATA_8_BITS,
        .parity = UART_PARITY_DISABLE,
        .stop_bits = UART_STOP_BITS_1,
        .flow_ctrl = UART_HW_FLOWCTRL_DISABLE,
        .source_clk = UART_SCLK_DEFAULT,
    };
    esp_err_t result = uart_param_config(PORT, &config);
    if (result != ESP_OK) {
        return result;
    }
    result = uart_set_pin(PORT, 17, 16, UART_PIN_NO_CHANGE, UART_PIN_NO_CHANGE);
    if (result != ESP_OK) {
        return result;
    }
    // XY-485 controls direction itself; no RTS/DE pin or RS485 RTS mode needed.
    return uart_driver_install(PORT, 256, 0, 0, NULL, 0);
}

esp_err_t lock_bus_query_all(uint8_t board_address, uint8_t *response,
                           size_t capacity, size_t *length)
{
    if (length == NULL) {
        return ESP_ERR_INVALID_ARG;
    }
    *length = 0;
    if (response == NULL || capacity < 5 || capacity > LOCK_BUS_MAX_FRAME_SIZE ||
        board_address < 1 || board_address > 31) {
        return ESP_ERR_INVALID_ARG;
    }

    const uint8_t request[] = {
        0x80, board_address, 0x00, 0x33,
        (uint8_t)(0x80 ^ board_address ^ 0x00 ^ 0x33),
    };
    esp_err_t result = uart_flush_input(PORT);
    if (result != ESP_OK) {
        return result;
    }
    if (uart_write_bytes(PORT, request, sizeof(request)) != sizeof(request)) {
        return ESP_FAIL;
    }
    result = uart_wait_tx_done(PORT, pdMS_TO_TICKS(100));
    if (result != ESP_OK) {
        return result;
    }

    // Wait for the first byte, then finish on a quiet period. Frame length is
    // not inferred from the number of physical channels (see ADR-0061).
    int received = uart_read_bytes(PORT, response, 1, pdMS_TO_TICKS(1000));
    if (received < 0) {
        return ESP_FAIL;
    }
    if (received == 0) {
        return ESP_ERR_TIMEOUT;
    }
    *length = (size_t)received;
    while (*length < capacity) {
        // Conservative 20 ms bench-test window at 9600 baud; two RTOS ticks.
        received = uart_read_bytes(PORT, response + *length, 1, pdMS_TO_TICKS(20));
        if (received < 0) {
            return ESP_FAIL;
        }
        if (received == 0) {
            break;
        }
        *length += (size_t)received;
    }
    if (*length == capacity) {
        return ESP_ERR_INVALID_SIZE;
    }
    if (*length < 5 || response[0] != 0x80 || response[1] != board_address ||
        response[*length - 2] != 0x33) {
        return ESP_ERR_INVALID_RESPONSE;
    }
    uint8_t checksum = 0;
    for (size_t i = 0; i < *length - 1; ++i) {
        checksum ^= response[i];
    }
    return checksum == response[*length - 1] ? ESP_OK : ESP_ERR_INVALID_CRC;
}
