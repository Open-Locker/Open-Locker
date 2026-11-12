#include <stdlib.h>
#include <string.h>

#include "Mockmqtt_client.h"
#include "lc_mqtt_espidf/transport.h"
#include "unity.h"

/*
 * The esp-mqtt adapter against a CMock mock of the real mqtt_client.h. The
 * test plays the esp-mqtt task: it takes the handler the adapter registers and
 * hands it events built as espressif/mqtt 1.1.0 builds them.
 */

static int fake_client;
static esp_event_handler_t handler;
static int subscribed_count;
static int subscribed_id;

static esp_err_t capture_handler(esp_mqtt_client_handle_t client, esp_mqtt_event_id_t event,
                                 esp_event_handler_t on_event, void *handler_args, int calls)
{
    (void)client;
    (void)event;
    (void)handler_args;
    (void)calls;
    handler = on_event;
    return ESP_OK;
}

static void on_subscribed(void *ctx, int message_id)
{
    (void)ctx;
    ++subscribed_count;
    subscribed_id = message_id;
}

/* A SUBACK as deliver_suback() in espressif/mqtt 1.1.0 raises it: a refusal only sets error_type. */
static void deliver_suback(int message_id, char return_code)
{
    esp_mqtt_error_codes_t error;
    memset(&error, 0, sizeof(error));
    if ((unsigned char)return_code >= 0x80) {
        error.error_type = MQTT_ERROR_TYPE_SUBSCRIBE_FAILED;
    }
    esp_mqtt_event_t event;
    memset(&event, 0, sizeof(event));
    event.event_id = MQTT_EVENT_SUBSCRIBED;
    event.msg_id = message_id;
    event.data = &return_code;
    event.data_len = 1;
    event.total_data_len = 1;
    event.error_handle = &error;
    handler(NULL, "MQTT_EVENTS", MQTT_EVENT_SUBSCRIBED, &event);
}

/* The adapter keeps one client for good, so it is started once, before the tests. */
static void start(void)
{
    const lc_mqtt_transport_config_t config = {.broker_uri = "mqtts://broker.test:8883",
                                               .client_id = "client-abc"};
    const lc_mqtt_handlers_t handlers = {.on_subscribed = on_subscribed};

    Mockmqtt_client_Init();
    esp_mqtt_client_init_ExpectAnyArgsAndReturn((esp_mqtt_client_handle_t)&fake_client);
    esp_mqtt_client_register_event_Stub(capture_handler);
    esp_mqtt_client_start_ExpectAnyArgsAndReturn(ESP_OK);
    TEST_ASSERT_TRUE(lc_mqtt_transport_start(&config, &handlers));
    TEST_ASSERT_NOT_NULL(handler);
    Mockmqtt_client_Verify();
}

void mqtt_transport_tests_reset(void)
{
    subscribed_count = 0;
    subscribed_id = -1;
}

/* This suite builds without CONFIG_LC_ALLOW_PLAINTEXT_MQTT, as a release does. */
static void test_a_release_build_refuses_a_plain_mqtt_broker(void)
{
    const lc_mqtt_transport_config_t config = {.broker_uri = "mqtt://broker.test:1883",
                                               .client_id = "client-abc"};
    const lc_mqtt_handlers_t handlers = {.on_subscribed = on_subscribed};

    Mockmqtt_client_Init();
    /* No esp-mqtt call is expected: a client is never created. */
    TEST_ASSERT_FALSE(lc_mqtt_transport_start(&config, &handlers));
    Mockmqtt_client_Verify();
}

static void test_the_client_is_started_and_its_handler_registered(void)
{
    start();
}

static void test_a_granted_subscription_is_reported(void)
{
    deliver_suback(7, 0x01);

    TEST_ASSERT_EQUAL_INT(1, subscribed_count);
    TEST_ASSERT_EQUAL_INT(7, subscribed_id);
}

/* A refusal must not count as a subscription: provisioning would send the one-time token. */
static void test_a_refused_subscription_is_not_reported(void)
{
    deliver_suback(7, (char)0x80);

    TEST_ASSERT_EQUAL_INT(0, subscribed_count);
}

void run_mqtt_transport_tests(void)
{
    RUN_TEST(test_a_release_build_refuses_a_plain_mqtt_broker);
    RUN_TEST(test_the_client_is_started_and_its_handler_registered);
    RUN_TEST(test_a_granted_subscription_is_reported);
    RUN_TEST(test_a_refused_subscription_is_not_reported);
}
