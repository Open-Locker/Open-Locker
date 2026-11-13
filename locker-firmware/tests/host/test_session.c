#include <string.h>

#include "fakes/app_fixture.h"
#include "fakes/emitted.h"
#include "lc_mqtt_codec/codec.h"
#include "unity.h"

/* What the connection to the broker means for the application. */

#define LOCKER_UUID "11111111-1111-1111-1111-111111111111"
#define OTHER_UUID "22222222-2222-2222-2222-222222222222"
#define CLIENT_ID "client-abc"

static const uint8_t RANDOM[16] = {1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16};

static char topic[LC_MQTT_TOPIC_MAX + 1];
static char encoded[512];

void setUp(void)
{
    fx_init();
}

void tearDown(void)
{
}

static void open(const char *transaction_id, const char *message_id)
{
    const lc_command_t command = fx_open(transaction_id, message_id, 1);
    lc_app_handle_command(&fx_app, &command);
}

void test_S3_H11_commands_before_the_subscription_is_confirmed_are_not_executed(void)
{
    /* A device that was configured earlier and has just restarted. */
    fx_configure();
    fx_restart();
    fx_reset_counts();

    open("tx-1", "msg-1");

    TEST_ASSERT_EQUAL_UINT(0, fx_bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(0, fake_outbound_attempts(&fx_outbound));
    TEST_ASSERT_NULL(fake_journal_entry(&fx_journal, "tx-1"));

    fx_connect();
    open("tx-1", "msg-1");
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);

    lc_app_on_disconnected(&fx_app);
    open("tx-2", "msg-2");
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    TEST_ASSERT_NULL(fake_journal_entry(&fx_journal, "tx-2"));
}

void test_S3_H12_topics_are_built_from_the_locker_uuid(void)
{
    static const struct {
        lc_topic_t which;
        const char *expected;
    } TOPICS[] = {
        {LC_TOPIC_COMMAND, "locker/" LOCKER_UUID "/command"},
        {LC_TOPIC_RESPONSE, "locker/" LOCKER_UUID "/response"},
        {LC_TOPIC_EVENT, "locker/" LOCKER_UUID "/event"},
        {LC_TOPIC_HEARTBEAT, "locker/" LOCKER_UUID "/state/heartbeat"},
        {LC_TOPIC_SNAPSHOT, "locker/" LOCKER_UUID "/state/compartments"},
        {LC_TOPIC_CONNECTION, "locker/" LOCKER_UUID "/state/connection"},
    };

    for (size_t i = 0; i < sizeof(TOPICS) / sizeof(TOPICS[0]); ++i) {
        TEST_ASSERT_TRUE(lc_mqtt_topic(TOPICS[i].which, LOCKER_UUID, topic, sizeof(topic)));
        TEST_ASSERT_EQUAL_STRING(TOPICS[i].expected, topic);
        /* Only the snapshot is retained. */
        TEST_ASSERT_EQUAL(TOPICS[i].which == LC_TOPIC_SNAPSHOT,
                          lc_mqtt_is_retained(TOPICS[i].which));
    }

    TEST_ASSERT_TRUE(lc_mqtt_registration_topic("one-time-token", topic, sizeof(topic)));
    TEST_ASSERT_EQUAL_STRING("locker/register/one-time-token", topic);
    TEST_ASSERT_TRUE(lc_mqtt_provisioning_reply_topic(CLIENT_ID, topic, sizeof(topic)));
    TEST_ASSERT_EQUAL_STRING("locker/provisioning/reply/" CLIENT_ID, topic);

    /* No topic without an identity, none that would not fit, none with wildcards. */
    TEST_ASSERT_FALSE(lc_mqtt_topic(LC_TOPIC_COMMAND, "", topic, sizeof(topic)));
    TEST_ASSERT_FALSE(lc_mqtt_topic(LC_TOPIC_COMMAND, LOCKER_UUID, topic, 16));
    TEST_ASSERT_FALSE(lc_mqtt_topic(LC_TOPIC_COMMAND, "+", topic, sizeof(topic)));
    TEST_ASSERT_FALSE(lc_mqtt_topic(LC_TOPIC_COMMAND, "a/b", topic, sizeof(topic)));
    TEST_ASSERT_FALSE(lc_mqtt_registration_topic("", topic, sizeof(topic)));
    TEST_ASSERT_FALSE(lc_mqtt_registration_topic("to/ken", topic, sizeof(topic)));
    TEST_ASSERT_FALSE(lc_mqtt_registration_topic("#", topic, sizeof(topic)));
}

void test_S3_H13_reconnect_flushes_pending_responses_and_forces_a_snapshot(void)
{
    fx_configure();
    fx_advance_acknowledging(2000);
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_SNAPSHOT]);

    open("tx-1", "msg-1");
    TEST_ASSERT_EQUAL_UINT(1, fx_outbound.accepted[FAKE_RESPONSE]);
    lc_app_on_disconnected(&fx_app);
    /* The acknowledgement was lost with the connection. */
    fx_outbound.acknowledged = fx_outbound.id_count;

    lc_app_on_connected(&fx_app);

    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_RESPONSE]);
    TEST_ASSERT_EQUAL_STRING("tx-1", fake_outbound_last_response(&fx_outbound)->transaction_id);
    TEST_ASSERT_EQUAL_UINT(1, fx_bus.unlock_count);
    fx_advance_acknowledging(2000);
    /* No door moved, yet the retained snapshot is published again. */
    TEST_ASSERT_EQUAL_UINT(2, fx_outbound.accepted[FAKE_SNAPSHOT]);
    TEST_ASSERT_TRUE(fake_journal_entry(&fx_journal, "tx-1")->delivered);
}

void test_S3_H14_nothing_is_published_while_offline(void)
{
    fx_configure();
    fx_advance_acknowledging(2000);

    lc_app_on_disconnected(&fx_app);
    unsigned published = fake_outbound_attempts(&fx_outbound);
    fx_bus.doors[0] = LC_DOOR_OPEN;
    fx_advance(3 * FX_HEARTBEAT_SECONDS * 1000);
    lc_app_on_publish_failed(&fx_app, 1);

    TEST_ASSERT_EQUAL_UINT(published, fake_outbound_attempts(&fx_outbound));
}

void test_S3_H15_last_will_and_orderly_offline_message_match_their_schema(void)
{
    lc_mqtt_envelope_t envelope;
    lc_mqtt_envelope(RANDOM, 1776195060, &envelope);

    TEST_ASSERT_TRUE(lc_mqtt_encode_connection_offline(LC_OFFLINE_LAST_WILL, &envelope, encoded,
                                                       sizeof(encoded)) > 0);
    cJSON *message = emit_message("payloads/state-connection-lost.json", encoded);
    TEST_ASSERT_EQUAL_STRING("offline", emitted_string(message, "status"));
    TEST_ASSERT_EQUAL_STRING("mqtt_last_will", emitted_string(message, "reason"));
    cJSON_Delete(message);

    TEST_ASSERT_TRUE(lc_mqtt_encode_connection_offline(LC_OFFLINE_SHUTDOWN, &envelope, encoded,
                                                       sizeof(encoded)) > 0);
    message = emit_message("payloads/state-connection-lost.json", encoded);
    TEST_ASSERT_EQUAL_STRING("offline", emitted_string(message, "status"));
    TEST_ASSERT_EQUAL_STRING("shutdown", emitted_string(message, "reason"));
    cJSON_Delete(message);

    TEST_ASSERT_TRUE(lc_mqtt_encode_registration(CLIENT_ID, &envelope, encoded, sizeof(encoded)) >
                     0);
    message = emit_message("messages/provisioning-request.json", encoded);
    TEST_ASSERT_EQUAL_STRING(CLIENT_ID, emitted_string(message, "client_id"));
    TEST_ASSERT_EQUAL_STRING(envelope.message_id, emitted_string(message, "message_id"));
    cJSON_Delete(message);
}

void test_S3_H16_only_the_command_topic_is_dispatched(void)
{
    TEST_ASSERT_EQUAL_INT(LC_INBOUND_COMMAND,
                          lc_mqtt_classify("locker/" LOCKER_UUID "/command", LOCKER_UUID,
                                           CLIENT_ID));

    static const char *const NOT_COMMANDS[] = {
        "locker/" LOCKER_UUID "/response",
        "locker/" LOCKER_UUID "/event",
        "locker/" LOCKER_UUID "/state/compartments",
        "locker/" LOCKER_UUID "/command/extra",
        "locker/" LOCKER_UUID "/commands",
        "locker/" OTHER_UUID "/command",
        "locker//command",
        "xlocker/" LOCKER_UUID "/command",
        "locker/register/one-time-token",
        "",
    };
    for (size_t i = 0; i < sizeof(NOT_COMMANDS) / sizeof(NOT_COMMANDS[0]); ++i) {
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_INBOUND_OTHER,
                                      lc_mqtt_classify(NOT_COMMANDS[i], LOCKER_UUID, CLIENT_ID),
                                      NOT_COMMANDS[i]);
    }

    /* A device without an identity has no command topic at all. */
    TEST_ASSERT_EQUAL_INT(LC_INBOUND_OTHER, lc_mqtt_classify("locker//command", "", CLIENT_ID));
    TEST_ASSERT_EQUAL_INT(LC_INBOUND_PROVISIONING_REPLY,
                          lc_mqtt_classify("locker/provisioning/reply/" CLIENT_ID, "", CLIENT_ID));
    TEST_ASSERT_EQUAL_INT(LC_INBOUND_OTHER,
                          lc_mqtt_classify("locker/provisioning/reply/someone-else", "",
                                           CLIENT_ID));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S3_H11_commands_before_the_subscription_is_confirmed_are_not_executed);
    RUN_TEST(test_S3_H12_topics_are_built_from_the_locker_uuid);
    RUN_TEST(test_S3_H13_reconnect_flushes_pending_responses_and_forces_a_snapshot);
    RUN_TEST(test_S3_H14_nothing_is_published_while_offline);
    RUN_TEST(test_S3_H15_last_will_and_orderly_offline_message_match_their_schema);
    RUN_TEST(test_S3_H16_only_the_command_topic_is_dispatched);
    return UNITY_END();
}
