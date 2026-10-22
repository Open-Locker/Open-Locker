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
#include "lc_application/boot_mode.h"
#include "lc_application/commission_device.h"
#include "lc_application/provision_device.h"
#include "lc_journal/journal.h"
#include "lc_net_wifi/wifi.h"
#include "lc_rs485_lock_board/bus.h"
#include "lc_rs485_lock_board/codec.h"
#include "lc_serial_uart/serial_uart.h"
#include "lc_softap_espidf/softap.h"
#include "lc_storage_nvs/storage.h"
#include "lc_usb_console/console.h"
#include "psa/crypto.h"
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

    size_t digest_len = 0;

    if (psa_hash_compute(PSA_ALG_SHA_256, (const uint8_t *)text, strlen(text), digest, sizeof(digest),
                         &digest_len) != PSA_SUCCESS ||
        digest_len != sizeof(digest)) {
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

/* ---- The setup network ---- */

/* The setup network hands a collected request to the owner task, on the portal channel. */
static bool submit_portal_setup(void *ctx, const lc_setup_request_t *request)
{
    (void)ctx;
    owner_event_t event;

    memset(&event, 0, sizeof(event));
    event.kind = OWNER_SETUP;
    event.channel = LC_COMMISSION_CHANNEL_PORTAL;
    event.u.setup = *request;
    bool posted = owner_post(&event);
    memset(&event, 0, sizeof(event));
    return posted;
}

static void portal_reply(lc_commission_result_t result)
{
    lc_softap_commission_result(result == LC_COMMISSION_SAVED);
}

/* Only for a unit with no settings that is not enrolled; see lc_boot_decide(). */
static void start_setup_network(void)
{
    static lc_setup_secrets_t secrets;
    const lc_softap_sink_t sink = {.submit = submit_portal_setup, .ctx = NULL};

    if (!lc_storage_load_setup_secrets(&secrets)) {
        /* No default password stands in: a unit without its label secrets has no setup network. */
        ESP_LOGE(TAG, "no setup secrets stored: the setup network stays closed");
        return;
    }
    console_glue_set_portal_reply(portal_reply);
    if (!lc_wifi_init(NULL, NULL) || !lc_softap_start(&secrets, &sink)) {
        ESP_LOGE(TAG, "the setup network could not be started");
    }
    memset(&secrets, 0, sizeof(secrets));
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

    /* Finish an interrupted wipe, then decide what this start is (ADR-0074). */
    const lc_boot_ports_t boot_ports = {
        .settings_store = settings_store,
        .identity_store = identity_store,
        .config_store = config_store,
    };
    const lc_boot_result_t boot = lc_boot_decide(&boot_ports);
    if (boot.repaired) {
        ESP_LOGW(TAG, "a half-finished reset was found and %s", boot.repair_failed
                                                                    ? "could not be fully repaired"
                                                                    : "finished");
    }

    lc_network_settings_t settings;
    const bool configured = settings_store.load(settings_store.ctx, &settings);

    const lc_system_port_t system = {.ctx = NULL, .restart = restart_device};

    /* Without network settings there is no broker: the console stands in for it. */
    const lc_app_ports_t app_ports = {
        .bus = create_bus(),
        .journal = create_journal(),
        .outbound = configured ? mqtt_session_outbound() : bench_outbound(),
        .config_store = config_store,
        .hasher = {.ctx = NULL, .sha256_hex = sha256_hex},
        .clock = clock_port,
        .system = system,
    };
    lc_app_init(&app, &app_ports);
    /* A torn last record is compacted away before recovery appends to the journal. */
    while (lc_journal_maintain(&journal)) {
        vTaskDelay(1);
    }
    lc_app_recover(&app);

    const lc_provision_ports_t provision_ports = {
        .gateway = mqtt_session_gateway(),
        .identity_store = identity_store,
        .clock = clock_port,
    };
    lc_provision_init(&provision, &provision_ports);

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
#if CONFIG_LC_ALLOW_PLAINTEXT_MQTT
    lc_commission_allow_plaintext_broker(&commission, true);
#endif

    const owner_context_t owner = {
        .app = &app,
        .provision = &provision,
        .commission = &commission,
        .journal = &journal,
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
    if (!button_task_start()) {
        ESP_LOGE(TAG, "the reset button could not be started");
    }

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
        if (boot.mode == LC_BOOT_SETUP) {
            start_setup_network();
        }
    }
    memset(&settings, 0, sizeof(settings));
}
