#include "lc_serial_uart/serial_uart.h"

#include "driver/uart.h"
#include "esp_err.h"
#include "esp_log.h"
#include "freertos/FreeRTOS.h"

#define RX_BUFFER_SIZE 512
/* Far longer than any request takes at the slowest supported baud rate. */
#define TX_DONE_TIMEOUT_MS 200

static const char *TAG = "lc_serial_uart";

static TickType_t ticks_for(uint32_t milliseconds)
{
    TickType_t ticks = pdMS_TO_TICKS(milliseconds);
    return (milliseconds > 0 && ticks == 0) ? 1 : ticks;
}

bool lc_serial_uart_init(lc_serial_uart_t *uart, const lc_serial_uart_config_t *config)
{
    const uart_config_t uart_config = {
        .baud_rate = config->baud_rate,
        .data_bits = UART_DATA_8_BITS,
        .parity = config->parity == LC_SERIAL_PARITY_EVEN  ? UART_PARITY_EVEN
                  : config->parity == LC_SERIAL_PARITY_ODD ? UART_PARITY_ODD
                                                           : UART_PARITY_DISABLE,
        .stop_bits = config->stop_bits == 2 ? UART_STOP_BITS_2 : UART_STOP_BITS_1,
        .flow_ctrl = UART_HW_FLOWCTRL_DISABLE,
        .source_clk = UART_SCLK_DEFAULT,
    };
    uart_port_t port = (uart_port_t)config->uart_num;

    uart->uart_num = config->uart_num;
    uart->ready = false;

    /* No transmit buffer: a write returns only once the bytes are in the FIFO. */
    esp_err_t err = uart_driver_install(port, RX_BUFFER_SIZE, 0, 0, NULL, 0);
    if (err == ESP_OK) {
        err = uart_param_config(port, &uart_config);
    }
    if (err == ESP_OK) {
        err = uart_set_pin(port, config->tx_pin, config->rx_pin, UART_PIN_NO_CHANGE,
                           UART_PIN_NO_CHANGE);
    }
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "UART %d setup failed: %s", config->uart_num, esp_err_to_name(err));
        return false;
    }
    uart->ready = true;
    return true;
}

int lc_serial_uart_write(void *ctx, const uint8_t *data, size_t length)
{
    const lc_serial_uart_t *uart = ctx;
    uart_port_t port = (uart_port_t)uart->uart_num;

    if (!uart->ready || length == 0) {
        return -1;
    }
    int queued = uart_write_bytes(port, data, length);
    if (queued <= 0) {
        return -1;
    }
    if (uart_wait_tx_done(port, ticks_for(TX_DONE_TIMEOUT_MS)) != ESP_OK) {
        /* Some bytes may still be in the FIFO: report a partial write. */
        return queued > 1 ? queued - 1 : 0;
    }
    return queued;
}

int lc_serial_uart_read(void *ctx, uint8_t *buffer, size_t capacity, uint32_t timeout_ms)
{
    const lc_serial_uart_t *uart = ctx;
    uart_port_t port = (uart_port_t)uart->uart_num;
    size_t buffered = 0;

    if (!uart->ready || capacity == 0) {
        return -1;
    }
    if (uart_get_buffered_data_len(port, &buffered) != ESP_OK) {
        return -1;
    }
    if (buffered == 0) {
        int first = uart_read_bytes(port, buffer, 1, ticks_for(timeout_ms));
        if (first <= 0) {
            return first;
        }
        if (uart_get_buffered_data_len(port, &buffered) != ESP_OK) {
            return -1;
        }
        size_t more = buffered < capacity - 1 ? buffered : capacity - 1;
        int rest = more > 0 ? uart_read_bytes(port, buffer + 1, more, 0) : 0;
        return rest < 0 ? -1 : 1 + rest;
    }
    return uart_read_bytes(port, buffer, buffered < capacity ? buffered : capacity, 0);
}
