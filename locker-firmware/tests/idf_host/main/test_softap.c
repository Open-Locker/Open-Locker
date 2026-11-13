#include <stdlib.h>
#include <string.h>

#include "Mockesp_event.h"
#include "Mockesp_srp.h"
#include "Mockesp_timer.h"
#include "Mockesp_wifi.h"
#include "Mockesp_wifi_default.h"
#include "Mocknetwork_config.h"
#include "Mockprotocomm.h"
#include "Mockprotocomm_httpd.h"
#include "lc_softap_espidf/softap.h"
#include "lc_softap_portal/ol_config.h"
#include "lc_softap_portal/portal.h"
#include "protocomm_security.h"
#include "protocomm_security2.h"
#include "unity.h"

/*
 * The setup network adapter against CMock mocks of ESP-IDF's Wi-Fi, timer and
 * event APIs, protocomm and network_provisioning. The test plays the SDK: it
 * takes the timer callback, the event handlers and the endpoint handlers the
 * adapter registers, and calls them as the HTTP server, event loop and timer
 * would.
 *
 * The adapter keeps its state between starts, so every test opens the portal
 * and ends with it closed.
 */

/* Defined by the real protocomm and Wi-Fi driver, which have no Linux build. */
const protocomm_security_t protocomm_security2;
ESP_EVENT_DEFINE_BASE(PROTOCOMM_SECURITY_SESSION_EVENT);
ESP_EVENT_DEFINE_BASE(WIFI_EVENT);

#define BROKER "mqtts://broker.test:8883"
#define TOKEN "one-time-token"

static int fake_comm;
static int fake_timer;
static int64_t now_us;
static esp_timer_cb_t window_check;
static esp_event_handler_t on_wifi_event;
static esp_event_handler_t on_session_event;
static protocomm_req_handler_t ol_config_handler;
static network_prov_config_handlers_t *wifi_handlers;
static char endpoints[8][16];
static int endpoint_count;
static esp_err_t httpd_start_result;
static esp_err_t timer_start_result;
static int wifi_started;
static int wifi_stopped;
static int deauthed_aid;
static int submit_count;
static bool answer_saved;
static lc_setup_request_t submitted;

/* ---- The SDK side ---- */

static esp_err_t capture_timer(const esp_timer_create_args_t *args, esp_timer_handle_t *out, int calls)
{
    (void)calls;
    window_check = args->callback;
    *out = (esp_timer_handle_t)&fake_timer;
    return ESP_OK;
}

static esp_err_t timer_start(esp_timer_handle_t timer, uint64_t period, int calls)
{
    (void)timer;
    (void)period;
    (void)calls;
    return timer_start_result;
}

static int64_t clock_now(int calls)
{
    (void)calls;
    return now_us;
}

static esp_err_t capture_event(esp_event_base_t base, int32_t id, esp_event_handler_t handler, void *arg,
                               int calls)
{
    (void)id;
    (void)arg;
    (void)calls;
    if (base == WIFI_EVENT) {
        on_wifi_event = handler;
    } else if (base == PROTOCOMM_SECURITY_SESSION_EVENT) {
        on_session_event = handler;
    }
    return ESP_OK;
}

static esp_err_t fake_srp(const char *user, int user_len, const char *pass, int pass_len, char **salt,
                          int salt_len, char **verifier, int *verifier_len, int calls)
{
    (void)user;
    (void)user_len;
    (void)pass;
    (void)pass_len;
    (void)calls;
    *salt = calloc(1, (size_t)salt_len);
    *verifier = calloc(1, 32);
    *verifier_len = 32;
    return ESP_OK;
}

static esp_err_t httpd_start(protocomm_t *pc, const protocomm_httpd_config_t *config, int calls)
{
    (void)pc;
    (void)config;
    (void)calls;
    return httpd_start_result;
}

static void record_endpoint(const char *name)
{
    if (endpoint_count < 8) {
        strncpy(endpoints[endpoint_count++], name, sizeof(endpoints[0]) - 1);
    }
}

static esp_err_t record_security(protocomm_t *pc, const char *name, const protocomm_security_t *security,
                                 const void *params, int calls)
{
    (void)pc;
    (void)security;
    (void)params;
    (void)calls;
    record_endpoint(name);
    return ESP_OK;
}

static esp_err_t sec_version(protocomm_t *pc, int *version, uint8_t *patch, int calls)
{
    (void)pc;
    (void)calls;
    *version = 2;
    *patch = 1;
    return ESP_OK;
}

static esp_err_t record_version(protocomm_t *pc, const char *name, const char *info, int calls)
{
    (void)pc;
    (void)info;
    (void)calls;
    record_endpoint(name);
    return ESP_OK;
}

static esp_err_t capture_endpoint(protocomm_t *pc, const char *name, protocomm_req_handler_t handler,
                                  void *priv, int calls)
{
    (void)pc;
    (void)calls;
    record_endpoint(name);
    if (strcmp(name, "ol-config") == 0) {
        ol_config_handler = handler;
    } else if (strcmp(name, "prov-config") == 0) {
        wifi_handlers = priv;
    }
    return ESP_OK;
}

static esp_err_t count_wifi_start(int calls)
{
    (void)calls;
    ++wifi_started;
    return ESP_OK;
}

static esp_err_t count_wifi_stop(int calls)
{
    (void)calls;
    ++wifi_stopped;
    return ESP_OK;
}

static esp_err_t record_deauth(uint16_t aid, int calls)
{
    (void)calls;
    deauthed_aid = aid;
    return ESP_OK;
}

/* ---- The owner task side ---- */

static bool submit(void *ctx, const lc_setup_request_t *request)
{
    (void)ctx;
    ++submit_count;
    submitted = *request;
    lc_softap_commission_result(answer_saved);
    return true;
}

/* ---- Helpers ---- */

static void reset_mocks(void)
{
    Mockesp_timer_Init();
    Mockesp_event_Init();
    Mockesp_wifi_Init();
    Mockesp_wifi_default_Init();
    Mockesp_srp_Init();
    Mockprotocomm_Init();
    Mockprotocomm_httpd_Init();
    Mocknetwork_config_Init();

    esp_timer_create_Stub(capture_timer);
    esp_timer_start_periodic_Stub(timer_start);
    esp_timer_stop_IgnoreAndReturn(ESP_OK);
    esp_timer_get_time_Stub(clock_now);
    esp_event_handler_register_Stub(capture_event);
    esp_netif_create_default_wifi_ap_IgnoreAndReturn(NULL);
    esp_wifi_set_mode_IgnoreAndReturn(ESP_OK);
    esp_wifi_set_config_IgnoreAndReturn(ESP_OK);
    esp_wifi_start_Stub(count_wifi_start);
    esp_wifi_stop_Stub(count_wifi_stop);
    esp_wifi_deauth_sta_Stub(record_deauth);
    esp_srp_gen_salt_verifier_Stub(fake_srp);
    protocomm_new_IgnoreAndReturn((protocomm_t *)&fake_comm);
    protocomm_httpd_start_Stub(httpd_start);
    protocomm_set_security_Stub(record_security);
    protocomm_get_sec_version_Stub(sec_version);
    protocomm_set_version_Stub(record_version);
    protocomm_add_endpoint_Stub(capture_endpoint);
    protocomm_httpd_stop_IgnoreAndReturn(ESP_OK);
    protocomm_delete_Ignore();
}

static bool start(void)
{
    const lc_setup_secrets_t secrets = {.ssid = "OpenLocker-test", .passphrase = "label-passphrase",
                                        .setup_password = "label-setup-password"};
    const lc_softap_sink_t sink = {.submit = submit};

    reset_mocks();
    now_us = 1000 * 1000;
    endpoint_count = 0;
    httpd_start_result = ESP_OK;
    timer_start_result = ESP_OK;
    wifi_started = wifi_stopped = 0;
    deauthed_aid = -1;
    submit_count = 0;
    answer_saved = true;
    memset(&submitted, 0, sizeof(submitted));
    return lc_softap_start(&secrets, &sink);
}

/* The window runs out; the next check takes the radio down. */
static void close_by_timeout(void)
{
    now_us += (int64_t)(LC_PORTAL_WINDOW_MS + 1000) * 1000;
    window_check(NULL);
}

static void station(int32_t event, uint16_t aid)
{
    if (event == WIFI_EVENT_AP_STACONNECTED) {
        wifi_event_ap_staconnected_t data = {.aid = aid};
        on_wifi_event(NULL, WIFI_EVENT, event, &data);
    } else {
        wifi_event_ap_stadisconnected_t data = {.aid = aid};
        on_wifi_event(NULL, WIFI_EVENT, event, &data);
    }
}

static void session(int32_t event)
{
    on_session_event(NULL, PROTOCOMM_SECURITY_SESSION_EVENT, event, NULL);
}

static size_t add_item(uint8_t *out, size_t at, uint8_t tag, const char *value)
{
    size_t length = strlen(value);
    out[at++] = tag;
    out[at++] = (uint8_t)length;
    memcpy(out + at, value, length);
    return at + length;
}

/* Sends ol-config as esp_prov does; returns the endpoint's status byte, or -1 when it refused. */
static int send_ol_config(const char *token)
{
    uint8_t payload[LC_OL_CONFIG_MAX];
    size_t at = 0;
    payload[at++] = LC_OL_CONFIG_VERSION;
    at = add_item(payload, at, 1, BROKER);
    at = add_item(payload, at, 2, "bootstrap-user");
    at = add_item(payload, at, 3, "bootstrap-password");
    at = add_item(payload, at, 4, token);

    uint8_t *reply = NULL;
    ssize_t reply_length = 0;
    if (ol_config_handler(1, payload, (ssize_t)at, &reply, &reply_length, NULL) != ESP_OK) {
        return -1;
    }
    TEST_ASSERT_EQUAL_INT(1, reply_length);
    int status = reply[0];
    free(reply);
    return status;
}

static esp_err_t send_wifi(const char *ssid)
{
    network_prov_config_set_wifi_data_t request;
    memset(&request, 0, sizeof(request));
    strncpy(request.ssid, ssid, sizeof(request.ssid) - 1);
    strncpy(request.password, "home-wifi-password", sizeof(request.password) - 1);
    return wifi_handlers->wifi_set_config_handler(&request, NULL);
}

static uint8_t accepted_byte(void)
{
    uint8_t byte;
    (void)lc_ol_config_reply(true, &byte);
    return byte;
}

/* ---- Tests ---- */

static void test_the_setup_network_registers_four_endpoints_only(void)
{
    TEST_ASSERT_TRUE(start());

    TEST_ASSERT_EQUAL_INT(4, endpoint_count);
    TEST_ASSERT_EQUAL_STRING("prov-session", endpoints[0]);
    TEST_ASSERT_EQUAL_STRING("proto-ver", endpoints[1]);
    TEST_ASSERT_EQUAL_STRING("prov-config", endpoints[2]);
    TEST_ASSERT_EQUAL_STRING("ol-config", endpoints[3]);
    close_by_timeout();
}

static void test_a_saved_request_carries_both_parts_and_keeps_the_radio_for_the_restart(void)
{
    TEST_ASSERT_TRUE(start());
    station(WIFI_EVENT_AP_STACONNECTED, 1);
    session(PROTOCOMM_SECURITY_SESSION_SETUP_OK);

    TEST_ASSERT_EQUAL_INT(accepted_byte(), send_ol_config(TOKEN));
    TEST_ASSERT_EQUAL_INT(0, submit_count);
    TEST_ASSERT_EQUAL_INT(ESP_OK, send_wifi("home-wifi"));

    TEST_ASSERT_EQUAL_INT(1, submit_count);
    TEST_ASSERT_EQUAL_STRING("home-wifi", submitted.ssid);
    TEST_ASSERT_EQUAL_STRING(BROKER, submitted.broker_url);
    TEST_ASSERT_EQUAL_STRING(TOKEN, submitted.token);

    /* The next check closes the portal; the unit restarts into station mode, so Wi-Fi stays on. */
    window_check(NULL);
    TEST_ASSERT_EQUAL_INT(0, wifi_stopped);
}

static void test_the_window_closes_after_ten_minutes_and_stops_the_radio(void)
{
    TEST_ASSERT_TRUE(start());

    now_us += (int64_t)(LC_PORTAL_WINDOW_MS - 1000) * 1000;
    window_check(NULL);
    TEST_ASSERT_EQUAL_INT(0, wifi_stopped);

    close_by_timeout();
    TEST_ASSERT_EQUAL_INT(1, wifi_stopped);
}

static void test_a_second_station_is_sent_away(void)
{
    TEST_ASSERT_TRUE(start());
    station(WIFI_EVENT_AP_STACONNECTED, 1);
    station(WIFI_EVENT_AP_STACONNECTED, 2);

    TEST_ASSERT_EQUAL_INT(2, deauthed_aid);
    close_by_timeout();
}

static void test_no_endpoint_answers_before_a_session(void)
{
    TEST_ASSERT_TRUE(start());
    station(WIFI_EVENT_AP_STACONNECTED, 1);

    TEST_ASSERT_EQUAL_INT(-1, send_ol_config(TOKEN));
    TEST_ASSERT_EQUAL_INT(ESP_FAIL, send_wifi("home-wifi"));
    TEST_ASSERT_EQUAL_INT(0, submit_count);
    close_by_timeout();
}

static void test_a_refused_request_wipes_what_was_collected(void)
{
    TEST_ASSERT_TRUE(start());
    station(WIFI_EVENT_AP_STACONNECTED, 1);
    session(PROTOCOMM_SECURITY_SESSION_SETUP_OK);
    answer_saved = false;

    TEST_ASSERT_EQUAL_INT(accepted_byte(), send_ol_config(TOKEN));
    TEST_ASSERT_EQUAL_INT(ESP_FAIL, send_wifi("home-wifi"));
    TEST_ASSERT_EQUAL_INT(1, submit_count);

    /* Wi-Fi alone again: the refused ol-config is gone, so nothing is submitted. */
    TEST_ASSERT_EQUAL_INT(ESP_OK, send_wifi("home-wifi"));
    TEST_ASSERT_EQUAL_INT(1, submit_count);
    close_by_timeout();
}

/* A start that fails part-way takes the access point down again: no network without its portal. */
static void test_a_failed_start_leaves_no_access_point_up(void)
{
    const lc_setup_secrets_t secrets = {.ssid = "OpenLocker-test", .passphrase = "label-passphrase",
                                        .setup_password = "label-setup-password"};
    const lc_softap_sink_t sink = {.submit = submit};

    TEST_ASSERT_TRUE(start());
    close_by_timeout();
    reset_mocks();
    wifi_started = wifi_stopped = 0;
    httpd_start_result = ESP_FAIL;

    TEST_ASSERT_FALSE(lc_softap_start(&secrets, &sink));
    TEST_ASSERT_EQUAL_INT_MESSAGE(wifi_started, wifi_stopped, "the access point was left running");

    /* The same when the window's timer cannot start: the network would never close. */
    reset_mocks();
    wifi_started = wifi_stopped = 0;
    httpd_start_result = ESP_OK;
    timer_start_result = ESP_FAIL;
    TEST_ASSERT_FALSE(lc_softap_start(&secrets, &sink));
    TEST_ASSERT_EQUAL_INT_MESSAGE(wifi_started, wifi_stopped, "the access point was left running");
}

/* Half a request leaves with its station: the next one cannot complete it. */
static void test_the_ol_config_of_a_station_that_left_is_not_paired_with_the_next(void)
{
    TEST_ASSERT_TRUE(start());
    station(WIFI_EVENT_AP_STACONNECTED, 1);
    session(PROTOCOMM_SECURITY_SESSION_SETUP_OK);
    TEST_ASSERT_EQUAL_INT(accepted_byte(), send_ol_config("first-station-token"));
    station(WIFI_EVENT_AP_STADISCONNECTED, 1);

    station(WIFI_EVENT_AP_STACONNECTED, 2);
    session(PROTOCOMM_SECURITY_SESSION_SETUP_OK);
    (void)send_wifi("second-station-wifi");

    TEST_ASSERT_EQUAL_INT_MESSAGE(0, submit_count, "the first station's token went out with the second's Wi-Fi");
    close_by_timeout();
}

void softap_tests(void)
{
    RUN_TEST(test_the_setup_network_registers_four_endpoints_only);
    RUN_TEST(test_a_saved_request_carries_both_parts_and_keeps_the_radio_for_the_restart);
    RUN_TEST(test_the_window_closes_after_ten_minutes_and_stops_the_radio);
    RUN_TEST(test_a_second_station_is_sent_away);
    RUN_TEST(test_no_endpoint_answers_before_a_session);
    RUN_TEST(test_a_refused_request_wipes_what_was_collected);
    RUN_TEST(test_a_failed_start_leaves_no_access_point_up);
    RUN_TEST(test_the_ol_config_of_a_station_that_left_is_not_paired_with_the_next);
}
