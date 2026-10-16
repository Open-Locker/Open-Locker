#include <stdio.h>
#include <string.h>

#include "console_glue.h"
#include "esp_log.h"
#include "esp_random.h"
#include "esp_system.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "lc_application/app.h"
#include "lc_application/commission_device.h"
#include "lc_application/inbox.h"
#include "lc_application/provision_device.h"
#include "lc_journal/journal.h"
#include "lc_net_wifi/wifi.h"
#include "lc_rs485_lock_board/bus.h"
#include "lc_rs485_lock_board/codec.h"
#include "lc_serial_uart/serial_uart.h"
#include "lc_storage_nvs/storage.h"
#include "lc_usb_console/console.h"
#include "mbedtls/sha256.h"
#include "runtime.h"
#include "sdkconfig.h"

/*
 * Composition root: the only place that constructs adapters and wires them to
 * ports, and where the tasks are started. Nothing here writes to the RS485
 * line: the first request is sent when a command or a console line asks.
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

#define JOURNAL_PARTITION "journal"
#define RESTART_DELAY_MS 500

static const char *TAG = "app_main";

/* Large objects live here, not on the stack of app_main. */
static lc_serial_uart_t rs485_uart;
static lc_rs485_bus_t rs485_bus;
static lc_partition_t journal_partition;
static lc_journal_t journal;
static lc_app_t app;
static lc_inbox_t inbox;
static lc_provision_t provision;
static lc_commission_t commission;
static lc_console_t console;

/* ---- Small ports ---- */

static int64_t monotonic_ms(void *ctx)
{
    (void)ctx;
    return esp_timer_get_time() / 1000;
}

static bool sha256_hex(void *ctx, const char *text, char out[LC_SHA256_HEX_SIZE])
{
    (void)ctx;
    uint8_t digest[32];

    if (mbedtls_sha256((const unsigned char *)text, strlen(text), digest, 0) != 0) {
        return false;
    }
    for (size_t i = 0; i < sizeof(digest); ++i) {
        snprintf(out + i * 2, 3, "%02x", digest[i]);
    }
    return true;
}

/* Gives the output a moment to leave the chip before it goes away. */
static void restart_device(void *ctx)
{
    (void)ctx;
    vTaskDelay(pdMS_TO_TICKS(RESTART_DELAY_MS));
    esp_restart();
}

static void stop_commands(void *ctx)
{
    (void)ctx;
    lc_app_begin_closing(&app);
}

static uint32_t unlock_transmissions(void *ctx)
{
    (void)ctx;
    return lc_rs485_bus_unlock_transmissions(&rs485_bus);
}

static bool self_test(void *ctx)
{
    (void)ctx;
    return lc_rs485_codec_self_test();
}

static void on_wifi_state(void *ctx, bool up)
{
    (void)ctx;
    owner_event_t event;

    memset(&event, 0, sizeof(event));
    event.kind = up ? OWNER_NETWORK_UP : OWNER_NETWORK_DOWN;
    (void)owner_post(&event);
}

/* ---- Construction ---- */

/* A fresh broker session identity, kept until a reset. */
static void ensure_client_id(const lc_identity_store_port_t *store)
{
    lc_identity_t identity;

    if (!store->load(store->ctx, &identity)) {
        memset(&identity, 0, sizeof(identity));
    }
    if (identity.client_id[0] != '\0') {
        return;
    }
    uint8_t random[8];
    esp_fill_random(random, sizeof(random));
    snprintf(identity.client_id, sizeof(identity.client_id), "esp32-%02x%02x%02x%02x%02x%02x%02x%02x",
             random[0], random[1], random[2], random[3], random[4], random[5], random[6],
             random[7]);
    if (!store->save(store->ctx, &identity)) {
        ESP_LOGE(TAG, "the client id could not be stored");
    }
}

static lc_locker_bus_port_t create_bus(void)
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

    const lc_locker_bus_port_t bus = lc_rs485_bus_port(&rs485_bus);
    return bus_guard(&bus);
}

static lc_command_journal_port_t create_journal(void)
{
    /* A partition that is missing leaves an unhealthy journal, so nothing actuates. */
    if (!lc_partition_open(&journal_partition, JOURNAL_PARTITION)) {
        journal_partition.capacity = 0;
    }
    const lc_flash_io_t io = {
        .ctx = &journal_partition,
        .capacity = journal_partition.capacity,
        .read = lc_partition_read,
        .write = lc_partition_write,
        .erase = lc_partition_erase,
    };
    if (!lc_journal_open(&journal, io)) {
        ESP_LOGE(TAG, "the command journal is not healthy: commands are refused until it is erased");
    }
    return lc_journal_port(&journal);
}

void app_main(void)
{
    if (!lc_storage_init()) {
        ESP_LOGE(TAG, "storage unavailable");
    }
    const lc_settings_store_port_t settings_store = lc_storage_settings_store();
    const lc_identity_store_port_t identity_store = lc_storage_identity_store();
    const lc_config_store_port_t config_store = lc_storage_config_store();
    const lc_clock_port_t clock_port = {.ctx = NULL, .now_ms = monotonic_ms};

    ensure_client_id(&identity_store);

    lc_network_settings_t settings;
    const bool configured = settings_store.load(settings_store.ctx, &settings);

    /* Without network settings there is no broker: the console stands in for it. */
    const lc_app_ports_t app_ports = {
        .bus = create_bus(),
        .journal = create_journal(),
        .outbound = configured ? mqtt_session_outbound() : bench_outbound(),
        .config_store = config_store,
        .hasher = {.ctx = NULL, .sha256_hex = sha256_hex},
        .clock = clock_port,
    };
    lc_inbox_init(&inbox);
    lc_app_init(&app, &app_ports);
    lc_app_recover(&app);

    const lc_provision_ports_t provision_ports = {
        .gateway = mqtt_session_gateway(),
        .identity_store = identity_store,
        .clock = clock_port,
    };
    lc_provision_init(&provision, &provision_ports);

    const lc_system_port_t system = {.ctx = NULL, .restart = restart_device};
    const lc_commission_ports_t commission_ports = {
        .settings_store = settings_store,
        .identity_store = identity_store,
        .config_store = config_store,
        .outbound = configured ? mqtt_session_outbound() : bench_outbound(),
        .system = system,
        .ctx = NULL,
        .stop_commands = stop_commands,
        .reply = console_glue_reply,
    };
    lc_commission_init(&commission, &commission_ports);

    const owner_context_t owner = {
        .app = &app,
        .provision = &provision,
        .commission = &commission,
        .inbox = &inbox,
        .settings_store = settings_store,
        .system = system,
        .bench_mode = !configured,
    };
    if (!owner_start(&owner)) {
        ESP_LOGE(TAG, "the owner task could not be started");
        return;
    }

    console_glue_init(&console, &app_ports.bus, unlock_transmissions, self_test);
    lc_usb_console_start(&console);

    if (configured) {
        if (!lc_wifi_start(&settings, on_wifi_state, NULL)) {
            ESP_LOGE(TAG, "Wi-Fi could not be started");
        }
    } else {
        /* Bench mode: the console is the broker, and it is always there. */
        lc_event_t connected;
        memset(&connected, 0, sizeof(connected));
        connected.kind = LC_EVENT_CONNECTED;
        (void)owner_post_app(&connected);
        ESP_LOGI(TAG, "no network settings: bench mode, commands come from the console");
    }
    memset(&settings, 0, sizeof(settings));
}
