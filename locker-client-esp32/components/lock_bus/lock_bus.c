#include "lock_bus.h"
#include <string.h>
#include <stdatomic.h>
#include "driver/uart.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/semphr.h"
#include "freertos/task.h"

#define PORT UART_NUM_2
#define QUIET_US 25000
#define TRANSACTION_US 1000000
typedef struct {
    bool unlock, opening, success;
    uint8_t board;
    unsigned address;
    lc_unlock_t outcome;
    lc_door_t *states;
    SemaphoreHandle_t done;
} request_t;
static QueueHandle_t requests;
static atomic_bool connected;
static atomic_uint unlock_transmissions;
static int64_t last_byte, retry_after;
static unsigned failures;

static bool exchange(request_t *r)
{
    uint8_t command[5], response[LC_FRAME_MAX];
    if (!lc_encode(r->board, r->address, r->unlock, command)) return false;
    /* Drain the bus to a measured conservative quiet interval. Both draining
     * and reception have absolute deadlines, so noise cannot monopolize UART. */
    int64_t start = esp_timer_get_time();
    last_byte = start; /* establish actual quiet now, including queued stale bytes */
    uint8_t discarded;
    while (esp_timer_get_time() - last_byte < QUIET_US) {
        if (esp_timer_get_time() - start > TRANSACTION_US) return false;
        if (uart_read_bytes(PORT, &discarded, 1, pdMS_TO_TICKS(1)) > 0) last_byte = esp_timer_get_time();
    }
    if (uart_flush_input(PORT) != ESP_OK) return false;
    if (r->unlock) r->outcome = LC_UNCERTAIN; /* write can be partial */
    if (r->unlock) ++unlock_transmissions;
    int written = uart_write_bytes(PORT, command, sizeof(command));
    if (written == 0 && r->unlock) r->outcome = LC_NOT_SENT;
    if (written != (int)sizeof(command) || uart_wait_tx_done(PORT, pdMS_TO_TICKS(100)) != ESP_OK) return false;
    start = esp_timer_get_time();
    last_byte = start;
    size_t length = 0;
    while (esp_timer_get_time() - start < TRANSACTION_US) {
        int n = uart_read_bytes(PORT, &discarded, 1, pdMS_TO_TICKS(1));
        if (n < 0) return false;
        if (n > 0) {
            last_byte = esp_timer_get_time();
            if (length == sizeof(response)) return false;
            response[length++] = discarded;
        } else if (length && esp_timer_get_time() - last_byte >= QUIET_US) {
            if (r->unlock) {
                if (!lc_ack(response, length, r->board, r->address)) return false;
                r->outcome = LC_ACKNOWLEDGED;
                return true;
            }
            return lc_status(response, length, r->board, r->opening, r->states);
        }
    }
    return false;
}

static void worker(void *unused)
{
    (void)unused;
    request_t *r;
    while (xQueueReceive(requests, &r, portMAX_DELAY) == pdTRUE) {
        r->outcome = LC_NOT_SENT;
        r->success = false;
        if (esp_timer_get_time() >= retry_after) {
            r->success = exchange(r);
            connected = r->success;
            if (r->success) failures = 0;
            else {
                retry_after = esp_timer_get_time() + (++failures >= 5 ? 60000000 : 5000000);
                if (failures >= 5) failures = 0;
            }
        }
        xSemaphoreGive(r->done);
    }
}

esp_err_t lock_bus_start(void)
{
    uart_config_t cfg = {.baud_rate = 9600, .data_bits = UART_DATA_8_BITS,
        .parity = UART_PARITY_DISABLE, .stop_bits = UART_STOP_BITS_1,
        .flow_ctrl = UART_HW_FLOWCTRL_DISABLE, .source_clk = UART_SCLK_DEFAULT};
    esp_err_t err = uart_param_config(PORT, &cfg);
    if (err == ESP_OK) err = uart_set_pin(PORT, 17, 16, UART_PIN_NO_CHANGE, UART_PIN_NO_CHANGE);
    if (err == ESP_OK) err = uart_driver_install(PORT, 256, 0, 0, NULL, 0);
    if (err != ESP_OK) return err;
    requests = xQueueCreate(4, sizeof(request_t *));
    if (!requests || xTaskCreate(worker, "lock_bus", 4096, NULL, 6, NULL) != pdPASS) return ESP_ERR_NO_MEM;
    return ESP_OK;
}

static void submit(request_t *r)
{
    r->done = xSemaphoreCreateBinary();
    if (!r->done) return;
    if (xQueueSend(requests, &r, pdMS_TO_TICKS(100)) == pdTRUE)
        xSemaphoreTake(r->done, portMAX_DELAY); /* request storage stays alive */
    vSemaphoreDelete(r->done);
}
lc_unlock_t lock_bus_unlock(uint8_t board, unsigned address)
{
    request_t r = {.unlock = true, .board = board, .address = address, .outcome = LC_NOT_SENT};
    submit(&r);
    return r.outcome;
}
bool lock_bus_read(uint8_t board, bool opening, lc_door_t states[255])
{
    for (size_t i = 0; i < 255; ++i) states[i] = LC_UNKNOWN;
    request_t r = {.board = board, .opening = opening, .states = states};
    submit(&r);
    return r.success;
}
bool lock_bus_connected(void) { return connected; }
uint32_t lock_bus_unlock_transmissions(void) { return unlock_transmissions; }
