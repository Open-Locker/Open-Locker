#include "lc_softap_espidf/softap.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "esp_event.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_srp.h"
#include "esp_timer.h"
#include "esp_wifi.h"
#include "freertos/FreeRTOS.h"
#include "freertos/semphr.h"
#include "lc_softap_portal/ol_config.h"
#include "lc_softap_portal/portal.h"
#include "protocomm.h"
#include "protocomm_httpd.h"
#include "protocomm_security.h"
#include "protocomm_security2.h"
#include "network_provisioning/network_config.h"

/*
 * Thin by design: ESP-IDF calls in, lc_softap_portal decides, lc_ol_config
 * collects. All shared state is behind one mutex, because the HTTP server, the
 * event loop, the timer and the owner task all reach it.
 *
 * UNVERIFIED (see softap.h).
 */

#define SESSION_ENDPOINT "prov-session"
#define VERSION_ENDPOINT "proto-ver"
#define WIFI_ENDPOINT "prov-config"
#define OL_ENDPOINT "ol-config"
/* The Security 2 user name esp_prov uses unless told otherwise. */
#define SRP_USERNAME "wifiprov"
#define SRP_SALT_LENGTH 16
#define AP_CHANNEL 1
#define WINDOW_CHECK_US (1000 * 1000)
/* Longest an endpoint waits for the owner task to commission the unit. */
#define RESULT_WAIT_MS 8000

/* What esp_prov reads first. The shape follows the provisioning manager's; the patch level is
 * the one protocomm runs, since esp_prov must match it. */
#define VERSION_INFO_FORMAT "{\"prov\":{\"ver\":\"v1.1\",\"sec_ver\":2,\"sec_patch_ver\":%u,\"cap\":[]}}"

static const char *TAG = "lc_softap";

static SemaphoreHandle_t lock;
static SemaphoreHandle_t result_ready;
static lc_portal_t portal;
static lc_collector_t collector;
static lc_softap_sink_t sink;
static protocomm_t *comm;
static esp_timer_handle_t window_timer;
static char *srp_salt;
static char *srp_verifier;
static protocomm_security2_params_t security_params;
static network_prov_config_handlers_t wifi_handlers;
static uint16_t current_aid;
static volatile bool commission_saved;
static bool running;

/* ---- Closing ---- */

/*
 * Takes the radio down once the portal has closed, whatever closed it. Runs on
 * the timer task with the lock released: stopping the HTTP server waits for its
 * handlers, and a handler may be waiting for the lock.
 */
static void teardown(void)
{
    xSemaphoreTake(lock, portMAX_DELAY);
    bool closed = running && !lc_portal_is_open(&portal);
    bool saved = portal.state == LC_PORTAL_CLOSED_SAVED;
    if (closed) {
        running = false;
    }
    xSemaphoreGive(lock);
    if (!closed) {
        return;
    }

    ESP_LOGI(TAG, "setup network closed (%d)", (int)portal.state);
    esp_timer_stop(window_timer);
    if (comm != NULL) {
        protocomm_httpd_stop(comm);
        protocomm_delete(comm);
        comm = NULL;
    }
    /* After "saved" the unit restarts into station mode; otherwise it just goes quiet. */
    if (!saved) {
        esp_wifi_stop();
    }
    xSemaphoreTake(lock, portMAX_DELAY);
    lc_collector_clear(&collector);
    xSemaphoreGive(lock);
    free(srp_salt);
    free(srp_verifier);
    srp_salt = NULL;
    srp_verifier = NULL;
}

/* Once a second: the window ends by itself, and a close from any other cause is acted on. */
static void window_check(void *arg)
{
    (void)arg;
    xSemaphoreTake(lock, portMAX_DELAY);
    (void)lc_portal_tick(&portal, esp_timer_get_time() / 1000);
    xSemaphoreGive(lock);
    teardown();
}

/* ---- Station and session events ---- */

static void on_wifi_event(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    (void)arg;
    (void)base;

    xSemaphoreTake(lock, portMAX_DELAY);
    if (id == WIFI_EVENT_AP_STACONNECTED) {
        const wifi_event_ap_staconnected_t *event = data;
        if (lc_portal_station_joined(&portal)) {
            current_aid = event->aid;
        } else {
            /* One station at a time: the second one is sent away. */
            esp_wifi_deauth_sta(event->aid);
        }
    } else if (id == WIFI_EVENT_AP_STADISCONNECTED) {
        const wifi_event_ap_stadisconnected_t *event = data;
        /* The refused station leaving must not end the session of the one that is connected. */
        if (event->aid == current_aid) {
            lc_portal_station_left(&portal);
            current_aid = 0;
        }
    }
    xSemaphoreGive(lock);
}

static void on_session_event(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    (void)arg;
    (void)base;
    (void)data;

    xSemaphoreTake(lock, portMAX_DELAY);
    if (id == PROTOCOMM_SECURITY_SESSION_SETUP_OK) {
        lc_portal_session_established(&portal);
    } else {
        /* The fifth failure closes the portal; the timer takes the radio down. */
        lc_portal_session_failed(&portal);
    }
    xSemaphoreGive(lock);
}

/* ---- Handing the request on ---- */

/* Waits for the owner task to commission the unit. True when it saved. */
static bool submit_collected(void)
{
    lc_setup_request_t request;
    bool taken;

    xSemaphoreTake(lock, portMAX_DELAY);
    taken = lc_collector_take(&collector, &request);
    xSemaphoreGive(lock);
    if (!taken) {
        return false;
    }
    (void)xSemaphoreTake(result_ready, 0);
    commission_saved = false;
    bool saved = sink.submit(sink.ctx, &request) &&
                 xSemaphoreTake(result_ready, pdMS_TO_TICKS(RESULT_WAIT_MS)) == pdTRUE &&
                 commission_saved;
    memset(&request, 0, sizeof(request));
    return saved;
}

void lc_softap_commission_result(bool saved)
{
    commission_saved = saved;
    if (saved) {
        xSemaphoreTake(lock, portMAX_DELAY);
        lc_portal_saved(&portal);
        xSemaphoreGive(lock);
    }
    xSemaphoreGive(result_ready);
}

/* ---- Endpoints ---- */

static esp_err_t ol_config_handler(uint32_t session_id, const uint8_t *inbuf, ssize_t inlen,
                                   uint8_t **outbuf, ssize_t *outlen, void *priv_data)
{
    (void)session_id;
    (void)priv_data;

    xSemaphoreTake(lock, portMAX_DELAY);
    bool allowed = lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG);
    bool accepted = allowed && inlen > 0 && lc_collector_ol_config(&collector, inbuf, (size_t)inlen);
    bool ready = accepted && lc_collector_ready(&collector);
    xSemaphoreGive(lock);
    if (!allowed) {
        return ESP_FAIL;
    }
    /* The second part to arrive triggers the commissioning, and its reply says how it went. */
    if (ready) {
        accepted = submit_collected();
    }
    *outbuf = malloc(1);
    if (*outbuf == NULL) {
        return ESP_ERR_NO_MEM;
    }
    *outlen = (ssize_t)lc_ol_config_reply(accepted, *outbuf);
    return ESP_OK;
}

static esp_err_t wifi_set_config(const network_prov_config_set_wifi_data_t *request, network_prov_ctx_t **ctx)
{
    (void)ctx;

    xSemaphoreTake(lock, portMAX_DELAY);
    bool allowed = lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_WIFI_CONFIG);
    bool accepted = allowed &&
                    lc_collector_wifi(&collector, (const uint8_t *)request->ssid,
                                      strnlen(request->ssid, sizeof(request->ssid)),
                                      (const uint8_t *)request->password,
                                      strnlen(request->password, sizeof(request->password)));
    bool ready = accepted && lc_collector_ready(&collector);
    xSemaphoreGive(lock);
    if (ready) {
        accepted = submit_collected();
    }
    return accepted ? ESP_OK : ESP_FAIL;
}

/* The settings are applied by the commissioning use case, not here. */
static esp_err_t wifi_apply_config(network_prov_ctx_t **ctx)
{
    (void)ctx;
    return ESP_OK;
}

/* The portal exposes no status: this is the same answer whatever is going on. */
static esp_err_t wifi_get_status(network_prov_config_get_wifi_data_t *response, network_prov_ctx_t **ctx)
{
    (void)ctx;
    memset(response, 0, sizeof(*response));
    response->wifi_state = NETWORK_PROV_WIFI_STA_CONNECTING;
    return ESP_OK;
}

/* ---- Start ---- */

static bool start_access_point(const lc_setup_secrets_t *secrets)
{
    wifi_config_t config;
    memset(&config, 0, sizeof(config));
    size_t ssid = strnlen(secrets->ssid, sizeof(config.ap.ssid));
    memcpy(config.ap.ssid, secrets->ssid, ssid);
    config.ap.ssid_len = (uint8_t)ssid;
    memcpy(config.ap.password, secrets->passphrase,
           strnlen(secrets->passphrase, sizeof(config.ap.password) - 1));
    config.ap.channel = AP_CHANNEL;
    config.ap.authmode = WIFI_AUTH_WPA2_PSK;
    config.ap.max_connection = 1;

    esp_netif_create_default_wifi_ap();
    esp_err_t err = esp_wifi_set_mode(WIFI_MODE_AP);
    if (err == ESP_OK) {
        err = esp_wifi_set_config(WIFI_IF_AP, &config);
    }
    memset(&config, 0, sizeof(config));
    if (err == ESP_OK) {
        err = esp_wifi_start();
    }
    return err == ESP_OK;
}

static bool set_version_endpoint(void)
{
    int sec_version = 0;
    uint8_t patch_version = 0;
    char info[96];

    if (protocomm_get_sec_version(comm, &sec_version, &patch_version) != ESP_OK) {
        return false;
    }
    snprintf(info, sizeof(info), VERSION_INFO_FORMAT, (unsigned)patch_version);
    return protocomm_set_version(comm, VERSION_ENDPOINT, info) == ESP_OK;
}

static bool start_protocomm(const lc_setup_secrets_t *secrets)
{
    int verifier_length = 0;

    if (esp_srp_gen_salt_verifier(SRP_USERNAME, (int)strlen(SRP_USERNAME), secrets->setup_password,
                                  (int)strlen(secrets->setup_password), &srp_salt, SRP_SALT_LENGTH,
                                  &srp_verifier, &verifier_length) != ESP_OK) {
        return false;
    }
    security_params = (protocomm_security2_params_t){
        .salt = srp_salt,
        .salt_len = SRP_SALT_LENGTH,
        .verifier = srp_verifier,
        .verifier_len = (uint16_t)verifier_length,
    };
    wifi_handlers = (network_prov_config_handlers_t){
        .wifi_get_status_handler = wifi_get_status,
        .wifi_set_config_handler = wifi_set_config,
        .wifi_apply_config_handler = wifi_apply_config,
        .ctx = NULL,
    };

    comm = protocomm_new();
    const protocomm_httpd_config_t http = {.ext_handle_provided = false,
                                           .data = {.config = PROTOCOMM_HTTPD_DEFAULT_CONFIG()}};
    return comm != NULL && protocomm_httpd_start(comm, &http) == ESP_OK &&
           protocomm_set_security(comm, SESSION_ENDPOINT, &protocomm_security2, &security_params) ==
               ESP_OK &&
           set_version_endpoint() &&
           protocomm_add_endpoint(comm, WIFI_ENDPOINT, network_prov_config_data_handler,
                                  &wifi_handlers) == ESP_OK &&
           protocomm_add_endpoint(comm, OL_ENDPOINT, ol_config_handler, NULL) == ESP_OK;
}

bool lc_softap_start(const lc_setup_secrets_t *secrets, const lc_softap_sink_t *callbacks)
{
    if (running) {
        return false;
    }
    sink = *callbacks;
    if (lock == NULL) {
        lock = xSemaphoreCreateMutex();
        result_ready = xSemaphoreCreateBinary();
    }
    if (lock == NULL || result_ready == NULL) {
        return false;
    }
    lc_portal_init(&portal);
    lc_collector_init(&collector);
    current_aid = 0;

    const esp_timer_create_args_t timer_args = {.callback = window_check, .name = "lc_softap_window"};
    bool ok = esp_timer_create(&timer_args, &window_timer) == ESP_OK &&
              esp_event_handler_register(WIFI_EVENT, ESP_EVENT_ANY_ID, on_wifi_event, NULL) == ESP_OK &&
              esp_event_handler_register(PROTOCOMM_SECURITY_SESSION_EVENT, ESP_EVENT_ANY_ID,
                                         on_session_event, NULL) == ESP_OK &&
              start_access_point(secrets) && start_protocomm(secrets);
    if (!ok) {
        ESP_LOGE(TAG, "the setup network could not be started");
        return false;
    }
    running = true;
    lc_portal_open(&portal, esp_timer_get_time() / 1000);
    ESP_LOGI(TAG, "setup network open for %d s", LC_PORTAL_WINDOW_MS / 1000);
    return esp_timer_start_periodic(window_timer, WINDOW_CHECK_US) == ESP_OK;
}
