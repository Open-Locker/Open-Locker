#include <string.h>

#include "lc_mqtt_espidf/transport.h"
#include "mqtt_client.h"
#include "unity.h"

/*
 * The esp-mqtt adapter, driven through stand-in SDK headers (fakes/esp_idf):
 * the test plays the esp-mqtt task and hands the adapter its events.
 */

static struct esp_mqtt_client {
    int unused;
} fake_client;
static esp_event_handler_t handler;
static int subscribed_count;
static int subscribed_id;

int esp_crt_bundle_attach(void *conf)
{
    (void)conf;
    return ESP_OK;
}

esp_mqtt_client_handle_t esp_mqtt_client_init(const esp_mqtt_client_config_t *config)
{
    (void)config;
    return &fake_client;
}

esp_err_t esp_mqtt_client_register_event(esp_mqtt_client_handle_t client,
                                         esp_mqtt_event_id_t event, esp_event_handler_t on_event,
                                         void *handler_args)
{
    (void)client;
    (void)event;
    (void)handler_args;
    handler = on_event;
    return ESP_OK;
}

esp_err_t esp_mqtt_client_start(esp_mqtt_client_handle_t client)
{
    (void)client;
    return ESP_OK;
}

esp_err_t esp_mqtt_client_destroy(esp_mqtt_client_handle_t client)
{
    (void)client;
    return ESP_OK;
}

int esp_mqtt_client_subscribe_single(esp_mqtt_client_handle_t client, const char *topic, int qos)
{
    (void)client;
    (void)topic;
    (void)qos;
    return 7;
}

int esp_mqtt_client_enqueue(esp_mqtt_client_handle_t client, const char *topic, const char *data,
                            int len, int qos, int retain, bool store)
{
    (void)client;
    (void)topic;
    (void)data;
    (void)len;
    (void)qos;
    (void)retain;
    (void)store;
    return 8;
}

static void on_subscribed(void *ctx, int message_id)
{
    (void)ctx;
    ++subscribed_count;
    subscribed_id = message_id;
}

/* The adapter keeps one client for good, so it is started once for the whole suite. */
static void start_once(void)
{
    static bool started;
    if (started) {
        return;
    }
    const lc_mqtt_transport_config_t config = {.broker_uri = "mqtts://broker.test:8883",
                                               .client_id = "client-abc"};
    const lc_mqtt_handlers_t handlers = {.on_subscribed = on_subscribed};
    TEST_ASSERT_TRUE(lc_mqtt_transport_start(&config, &handlers));
    TEST_ASSERT_NOT_NULL(handler);
    started = true;
}

/* A SUBACK as esp-mqtt 5.4 delivers it: one return code per topic, a refusal flagged in error_type. */
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

void setUp(void)
{
    start_once();
    subscribed_count = 0;
    subscribed_id = -1;
}

void tearDown(void)
{
}

void test_a_granted_subscription_is_reported(void)
{
    deliver_suback(7, 0x01);

    TEST_ASSERT_EQUAL_INT(1, subscribed_count);
    TEST_ASSERT_EQUAL_INT(7, subscribed_id);
}

/* A refusal must not count as a subscription: provisioning would send the one-time token. */
void test_a_refused_subscription_is_not_reported(void)
{
    deliver_suback(7, (char)0x80);

    TEST_ASSERT_EQUAL_INT(0, subscribed_count);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_a_granted_subscription_is_reported);
    RUN_TEST(test_a_refused_subscription_is_not_reported);
    return UNITY_END();
}
