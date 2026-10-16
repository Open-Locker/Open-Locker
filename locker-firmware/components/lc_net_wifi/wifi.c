#include "lc_net_wifi/wifi.h"

#include <string.h>
#include <time.h>

#include "esp_err.h"
#include "esp_event.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_netif_sntp.h"
#include "esp_timer.h"
#include "esp_wifi.h"

#define RETRY_FIRST_MS 1000
#define RETRY_LONGEST_MS 30000
/* 2023-11-14: any earlier time is a clock that was never set. */
#define EARLIEST_VALID_TIME 1700000000

static const char *TAG = "lc_wifi";

static lc_wifi_state_cb state_callback;
static void *state_ctx;
static esp_timer_handle_t retry_timer;
static uint32_t retry_delay_ms = RETRY_FIRST_MS;
static bool sntp_started;

static void notify(bool up)
{
    if (state_callback != NULL) {
        state_callback(state_ctx, up);
    }
}

static void retry_connect(void *arg)
{
    (void)arg;
    esp_wifi_connect();
}

static void on_wifi_event(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    (void)arg;
    (void)base;
    (void)data;

    if (id == WIFI_EVENT_STA_START) {
        esp_wifi_connect();
    } else if (id == WIFI_EVENT_STA_DISCONNECTED) {
        notify(false);
        /* Rejoin with a growing pause, so a missing access point is not hammered. */
        esp_timer_stop(retry_timer);
        esp_timer_start_once(retry_timer, (uint64_t)retry_delay_ms * 1000u);
        retry_delay_ms = retry_delay_ms * 2 > RETRY_LONGEST_MS ? RETRY_LONGEST_MS
                                                              : retry_delay_ms * 2;
    }
}

static void on_ip_event(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    (void)arg;
    (void)base;
    (void)data;

    if (id != IP_EVENT_STA_GOT_IP) {
        return;
    }
    retry_delay_ms = RETRY_FIRST_MS;
    if (!sntp_started) {
        esp_sntp_config_t sntp = ESP_NETIF_SNTP_DEFAULT_CONFIG("pool.ntp.org");
        if (esp_netif_sntp_init(&sntp) == ESP_OK) {
            sntp_started = true;
        } else {
            ESP_LOGE(TAG, "SNTP could not be started");
        }
    }
    notify(true);
}

bool lc_wifi_start(const lc_network_settings_t *settings, lc_wifi_state_cb callback, void *ctx)
{
    state_callback = callback;
    state_ctx = ctx;

    const esp_timer_create_args_t timer_args = {.callback = retry_connect, .name = "lc_wifi_retry"};
    esp_err_t err = esp_timer_create(&timer_args, &retry_timer);
    if (err == ESP_OK) {
        err = esp_netif_init();
    }
    if (err == ESP_OK) {
        err = esp_event_loop_create_default();
        if (err == ESP_ERR_INVALID_STATE) {
            err = ESP_OK; /* Someone else created it already. */
        }
    }
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "network stack failed: %s", esp_err_to_name(err));
        return false;
    }
    esp_netif_create_default_wifi_sta();

    const wifi_init_config_t init = WIFI_INIT_CONFIG_DEFAULT();
    err = esp_wifi_init(&init);
    if (err == ESP_OK) {
        err = esp_event_handler_register(WIFI_EVENT, ESP_EVENT_ANY_ID, on_wifi_event, NULL);
    }
    if (err == ESP_OK) {
        err = esp_event_handler_register(IP_EVENT, IP_EVENT_STA_GOT_IP, on_ip_event, NULL);
    }
    if (err == ESP_OK) {
        /* The credentials already live in our own record; the driver keeps no copy in NVS. */
        err = esp_wifi_set_storage(WIFI_STORAGE_RAM);
    }
    if (err == ESP_OK) {
        err = esp_wifi_set_mode(WIFI_MODE_STA);
    }
    if (err == ESP_OK) {
        wifi_config_t config;
        memset(&config, 0, sizeof(config));
        memcpy(config.sta.ssid, settings->ssid, strnlen(settings->ssid, sizeof(config.sta.ssid)));
        memcpy(config.sta.password, settings->wifi_password,
               strnlen(settings->wifi_password, sizeof(config.sta.password)));
        config.sta.threshold.authmode =
            settings->wifi_password[0] == '\0' ? WIFI_AUTH_OPEN : WIFI_AUTH_WPA2_PSK;
        config.sta.pmf_cfg.capable = true;
        config.sta.pmf_cfg.required = false;
        err = esp_wifi_set_config(WIFI_IF_STA, &config);
        memset(&config, 0, sizeof(config));
    }
    if (err == ESP_OK) {
        err = esp_wifi_start();
    }
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "Wi-Fi start failed: %s", esp_err_to_name(err));
        return false;
    }
    return true;
}

bool lc_wifi_time_valid(void)
{
    return time(NULL) >= EARLIEST_VALID_TIME;
}

int64_t lc_wifi_unix_seconds(void)
{
    return (int64_t)time(NULL);
}
