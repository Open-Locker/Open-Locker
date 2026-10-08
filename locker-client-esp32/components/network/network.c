#include "locker_network.h"
#include <string.h>
#include <stdio.h>
#include <time.h>
#include <stdatomic.h>
#include "esp_wifi.h"
#include "esp_event.h"
#include "esp_netif_sntp.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

static atomic_bool has_ip;
static bool configured;
static void wifi_event(void *arg, esp_event_base_t base, int32_t id, void *event)
{
    (void)arg; (void)event;
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_START && configured) esp_wifi_connect();
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_DISCONNECTED) {
        has_ip = false;
        if (configured) esp_wifi_connect();
    }
    if (base == IP_EVENT && id == IP_EVENT_STA_GOT_IP) has_ip = true;
}
esp_err_t locker_network_init(const locker_settings_t *s)
{
    esp_err_t err = esp_netif_init();
    if (err != ESP_OK) return err;
    err = esp_event_loop_create_default();
    if (err != ESP_OK) return err;
    esp_netif_create_default_wifi_sta();
    wifi_init_config_t init = WIFI_INIT_CONFIG_DEFAULT();
    err = esp_wifi_init(&init);
    if (err != ESP_OK) return err;
    ESP_ERROR_CHECK(esp_wifi_set_storage(WIFI_STORAGE_RAM));
    ESP_ERROR_CHECK(esp_event_handler_register(WIFI_EVENT, ESP_EVENT_ANY_ID, wifi_event, NULL));
    ESP_ERROR_CHECK(esp_event_handler_register(IP_EVENT, IP_EVENT_STA_GOT_IP, wifi_event, NULL));
    ESP_ERROR_CHECK(esp_wifi_set_mode(WIFI_MODE_STA));
    configured = s->configured;
    if (configured) {
        wifi_config_t sta = {0};
        memcpy(sta.sta.ssid, s->ssid, strlen(s->ssid));
        memcpy(sta.sta.password, s->wifi_password, strlen(s->wifi_password));
        sta.sta.pmf_cfg.capable = true;
        ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_STA, &sta));
    }
    ESP_ERROR_CHECK(esp_wifi_start());
    esp_sntp_config_t time_config = ESP_NETIF_SNTP_DEFAULT_CONFIG("pool.ntp.org");
    return esp_netif_sntp_init(&time_config);
}
bool locker_network_ready(void) { return has_ip && time(NULL) >= 1767225600; }
