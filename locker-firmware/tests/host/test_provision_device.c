#include <stdio.h>
#include <string.h>

#include "fakes/fake_ports.h"
#include "lc_application/provision_device.h"
#include "lc_mqtt_codec/codec.h"
#include "unity.h"

#define CLIENT_ID "client-abc"
#define TOKEN "one-time-token"
#define LOCKER_UUID "11111111-1111-1111-1111-111111111111"

static const char SUCCESS_REPLY[] =
    "{\"message_id\":\"msg-prov-reply-001\",\"status\":\"success\","
    "\"timestamp\":\"2026-04-14T19:35:01Z\",\"data\":{\"mqtt_user\":\"issued-user\","
    "\"mqtt_password\":\"issued-password\",\"locker_uuid\":\"" LOCKER_UUID "\"}}";
static const char ERROR_REPLY[] =
    "{\"message_id\":\"msg-prov-reply-err-001\",\"status\":\"error\","
    "\"timestamp\":\"2026-04-14T19:35:01Z\","
    "\"message\":\"Invalid or expired provisioning token.\"}";

static fake_clock_t clock;
static fake_gateway_t gateway;
static fake_identity_store_t store;
static lc_provision_t provision;

static void start(void)
{
    const lc_provision_ports_t ports = {
        .gateway = fake_gateway_port(&gateway),
        .identity_store = fake_identity_store_port(&store),
        .clock = fake_clock_port(&clock),
    };
    lc_provision_init(&provision, &ports);
}

void setUp(void)
{
    fake_trace_reset();
    clock.now_ms = 1000;
    fake_gateway_init(&gateway);
    fake_identity_store_init(&store);
    store.has = true;
    snprintf(store.identity.client_id, sizeof(store.identity.client_id), CLIENT_ID);
    snprintf(store.identity.bootstrap_user, sizeof(store.identity.bootstrap_user), "bootstrap");
    snprintf(store.identity.bootstrap_password, sizeof(store.identity.bootstrap_password),
             "bootstrap-secret");
    snprintf(store.identity.token, sizeof(store.identity.token), TOKEN);
    start();
}

void tearDown(void)
{
}

static lc_provision_state_t state(void)
{
    return lc_provision_state(&provision);
}

/* What the owner task does with a message while the device is not enrolled. */
static void deliver(const char *topic, const char *payload)
{
    lc_provisioning_reply_t reply;
    if (lc_mqtt_classify(topic, "", CLIENT_ID) != LC_INBOUND_PROVISIONING_REPLY) {
        return;
    }
    if (!lc_mqtt_decode_provisioning_reply(payload, strlen(payload), &reply)) {
        return;
    }
    lc_provision_on_reply(&provision, &reply);
}

static void deliver_reply(const char *payload)
{
    deliver("locker/provisioning/reply/" CLIENT_ID, payload);
}

static void register_and_wait(void)
{
    lc_provision_on_connected(&provision);
    lc_provision_on_subscribed(&provision);
    TEST_ASSERT_EQUAL_UINT(1, gateway.registration_count);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_WAITING_FOR_REPLY, state());
}

/* Everything that could tempt the device into registering again. */
static void provoke_a_retry(void)
{
    lc_provision_on_connected(&provision);
    lc_provision_on_subscribed(&provision);
    clock.now_ms += 10 * LC_PROVISION_REPLY_TIMEOUT_MS;
    lc_provision_tick(&provision);
    lc_provision_on_connected(&provision);
    lc_provision_on_subscribed(&provision);
    start();
    lc_provision_on_connected(&provision);
    lc_provision_on_subscribed(&provision);
    lc_provision_tick(&provision);
}

void test_S3_H01_registration_is_published_only_after_the_subscription_is_confirmed(void)
{
    lc_provision_on_connected(&provision);

    TEST_ASSERT_EQUAL_UINT(1, gateway.subscribe_count);
    TEST_ASSERT_EQUAL_STRING(CLIENT_ID, gateway.client_id);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_WAITING_FOR_SUBSCRIPTION, state());
    lc_provision_tick(&provision);
    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);

    lc_provision_on_subscribed(&provision);

    TEST_ASSERT_EQUAL_UINT(1, gateway.registration_count);
    TEST_ASSERT_EQUAL_STRING(TOKEN, gateway.token);
    TEST_ASSERT_EQUAL_STRING(CLIENT_ID, gateway.client_id);
    TEST_ASSERT_TRUE(fake_trace_first("gateway.subscribe") < fake_trace_first("gateway.register"));

    /* A subscription confirmation nobody asked for does nothing. */
    setUp();
    lc_provision_on_subscribed(&provision);
    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
}

void test_S3_H02_pending_flag_is_saved_before_the_registration_is_published(void)
{
    register_and_wait();

    TEST_ASSERT_TRUE(fake_trace_first("identity.save") >= 0);
    TEST_ASSERT_TRUE(fake_trace_first("identity.save") < fake_trace_first("gateway.register"));
    TEST_ASSERT_TRUE(store.identity.enrollment_pending);
    TEST_ASSERT_FALSE(store.identity.enrolled);
    /* The token is still needed until the reply is stored. */
    TEST_ASSERT_EQUAL_STRING(TOKEN, store.identity.token);
}

void test_S3_H03_nothing_is_published_if_saving_the_pending_flag_fails(void)
{
    store.save_ok = false;

    lc_provision_on_connected(&provision);
    lc_provision_on_subscribed(&provision);

    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_STORE_FAILED, state());
    TEST_ASSERT_FALSE(store.identity.enrollment_pending);
}

void test_S3_H04_success_reply_stores_the_identity_in_one_write(void)
{
    register_and_wait();
    unsigned saves = store.save_count;

    deliver_reply(SUCCESS_REPLY);

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_ENROLLED, state());
    TEST_ASSERT_EQUAL_UINT(saves + 1, store.save_count);
    TEST_ASSERT_TRUE(store.identity.enrolled);
    TEST_ASSERT_FALSE(store.identity.enrollment_pending);
    TEST_ASSERT_EQUAL_STRING("issued-user", store.identity.mqtt_user);
    TEST_ASSERT_EQUAL_STRING("issued-password", store.identity.mqtt_password);
    TEST_ASSERT_EQUAL_STRING(LOCKER_UUID, store.identity.locker_uuid);
    TEST_ASSERT_EQUAL_STRING(CLIENT_ID, store.identity.client_id);
    TEST_ASSERT_EQUAL_STRING("", store.identity.token);
    TEST_ASSERT_EQUAL_STRING("", store.identity.bootstrap_user);
    TEST_ASSERT_EQUAL_STRING("", store.identity.bootstrap_password);
    TEST_ASSERT_TRUE(lc_provision_identity(&provision)->enrolled);
    TEST_ASSERT_EQUAL_STRING(LOCKER_UUID, lc_provision_identity(&provision)->locker_uuid);

    /* A second copy of the reply changes nothing. */
    deliver_reply(SUCCESS_REPLY);
    TEST_ASSERT_EQUAL_UINT(saves + 1, store.save_count);
}

void test_S3_H05_rejected_or_expired_token_is_reported_and_not_retried(void)
{
    register_and_wait();

    deliver_reply(ERROR_REPLY);

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_REJECTED, state());
    TEST_ASSERT_FALSE(store.identity.enrolled);

    provoke_a_retry();
    TEST_ASSERT_EQUAL_UINT(1, gateway.registration_count);
    TEST_ASSERT_FALSE(store.identity.enrolled);
}

void test_S3_H06_missing_reply_is_not_retried_and_leaves_the_pending_flag_set(void)
{
    register_and_wait();

    clock.now_ms += LC_PROVISION_REPLY_TIMEOUT_MS - 1;
    lc_provision_tick(&provision);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_WAITING_FOR_REPLY, state());

    clock.now_ms += 2;
    lc_provision_tick(&provision);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_OUTCOME_UNKNOWN, state());
    TEST_ASSERT_TRUE(store.identity.enrollment_pending);

    provoke_a_retry();
    TEST_ASSERT_EQUAL_UINT(1, gateway.registration_count);
    TEST_ASSERT_TRUE(store.identity.enrollment_pending);
}

/* The backend spent the token when it answered; a reply after the wait is still taken. */
void test_a_reply_that_arrives_after_the_wait_still_enrolls_the_device(void)
{
    register_and_wait();
    clock.now_ms += LC_PROVISION_REPLY_TIMEOUT_MS + 1;
    lc_provision_tick(&provision);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_OUTCOME_UNKNOWN, state());

    deliver_reply(SUCCESS_REPLY);

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_ENROLLED, state());
    TEST_ASSERT_TRUE(store.identity.enrolled);
    TEST_ASSERT_FALSE(store.identity.enrollment_pending);
    TEST_ASSERT_EQUAL_STRING(LOCKER_UUID, store.identity.locker_uuid);
    TEST_ASSERT_EQUAL_UINT(1, gateway.registration_count);
}

void test_S3_H07_device_that_boots_with_the_pending_flag_never_registers(void)
{
    store.identity.enrollment_pending = true;
    start();

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_OUTCOME_UNKNOWN, state());
    provoke_a_retry();

    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
    TEST_ASSERT_TRUE(store.identity.enrollment_pending);
}

/* A restart between the token and the reply: the broker may still hold the reply for this client. */
void test_a_device_that_boots_with_the_pending_flag_waits_for_a_late_reply(void)
{
    store.identity.enrollment_pending = true;
    start();

    TEST_ASSERT_TRUE(lc_provision_wants_session(&provision));
    lc_provision_on_connected(&provision);
    TEST_ASSERT_EQUAL_UINT(1, gateway.subscribe_count);
    lc_provision_on_subscribed(&provision);
    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_OUTCOME_UNKNOWN, state());

    deliver_reply(SUCCESS_REPLY);

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_ENROLLED, state());
    TEST_ASSERT_TRUE(store.identity.enrolled);
    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
}

void test_S3_H08_malformed_and_misdirected_replies_are_ignored(void)
{
    static const char *const MALFORMED[] = {
        "not json",
        "{}",
        /* Success without credentials. */
        "{\"message_id\":\"m\",\"status\":\"success\",\"timestamp\":\"2026-04-14T19:35:01Z\"}",
        /* Not a locker UUID. */
        "{\"message_id\":\"m\",\"status\":\"success\",\"timestamp\":\"2026-04-14T19:35:01Z\","
        "\"data\":{\"mqtt_user\":\"u\",\"mqtt_password\":\"p\",\"locker_uuid\":\"not-a-uuid\"}}",
        "{\"message_id\":\"m\",\"status\":\"success\",\"timestamp\":\"2026-04-14T19:35:01Z\","
        "\"data\":{\"mqtt_user\":\"u\",\"mqtt_password\":\"p\","
        "\"locker_uuid\":\"11111111-1111-1111-1111-11111111111/\"}}",
        /* No password, an empty user. */
        "{\"message_id\":\"m\",\"status\":\"success\",\"timestamp\":\"2026-04-14T19:35:01Z\","
        "\"data\":{\"mqtt_user\":\"u\",\"locker_uuid\":\"" LOCKER_UUID "\"}}",
        "{\"message_id\":\"m\",\"status\":\"success\",\"timestamp\":\"2026-04-14T19:35:01Z\","
        "\"data\":{\"mqtt_user\":\"\",\"mqtt_password\":\"p\",\"locker_uuid\":\"" LOCKER_UUID
        "\"}}",
        /* A status that is neither success nor error. */
        "{\"message_id\":\"m\",\"status\":\"maybe\",\"timestamp\":\"2026-04-14T19:35:01Z\"}",
        /* No message id, no timestamp. */
        "{\"status\":\"error\",\"timestamp\":\"2026-04-14T19:35:01Z\"}",
        "{\"message_id\":\"m\",\"status\":\"error\"}",
    };
    register_and_wait();
    unsigned saves = store.save_count;

    for (size_t i = 0; i < sizeof(MALFORMED) / sizeof(MALFORMED[0]); ++i) {
        deliver_reply(MALFORMED[i]);
        TEST_ASSERT_EQUAL_INT_MESSAGE(LC_PROVISION_WAITING_FOR_REPLY, state(), MALFORMED[i]);
    }
    /* A valid reply on a topic that is not this client's. */
    deliver("locker/provisioning/reply/another-client", SUCCESS_REPLY);
    deliver("locker/provisioning/reply/" CLIENT_ID "/extra", SUCCESS_REPLY);
    deliver("locker/" LOCKER_UUID "/command", SUCCESS_REPLY);

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_WAITING_FOR_REPLY, state());
    TEST_ASSERT_EQUAL_UINT(saves, store.save_count);

    /* The real reply still works afterwards. */
    deliver_reply(SUCCESS_REPLY);
    TEST_ASSERT_EQUAL_INT(LC_PROVISION_ENROLLED, state());
}

void test_S3_H09_device_is_not_enrolled_if_saving_the_credentials_fails(void)
{
    register_and_wait();
    store.save_ok = false;

    deliver_reply(SUCCESS_REPLY);

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_STORE_FAILED, state());
    TEST_ASSERT_FALSE(lc_provision_identity(&provision)->enrolled);
    TEST_ASSERT_FALSE(store.identity.enrolled);
    TEST_ASSERT_TRUE(store.identity.enrollment_pending);

    store.save_ok = true;
    provoke_a_retry();
    TEST_ASSERT_EQUAL_UINT(1, gateway.registration_count);
}

void test_S3_H10_enrolled_device_never_publishes_a_registration(void)
{
    store.identity.enrolled = true;
    snprintf(store.identity.locker_uuid, sizeof(store.identity.locker_uuid), LOCKER_UUID);
    snprintf(store.identity.mqtt_user, sizeof(store.identity.mqtt_user), "issued-user");
    snprintf(store.identity.mqtt_password, sizeof(store.identity.mqtt_password), "issued");
    start();

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_ENROLLED, state());
    provoke_a_retry();
    deliver_reply(SUCCESS_REPLY);

    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
    TEST_ASSERT_EQUAL_UINT(0, gateway.subscribe_count);
    TEST_ASSERT_EQUAL_UINT(0, store.save_count);

    /* No token, nothing to do. */
    setUp();
    store.identity.token[0] = '\0';
    start();
    provoke_a_retry();
    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
}

void test_a_device_whose_identity_cannot_be_loaded_is_not_enrolled_and_never_registers(void)
{
    fake_identity_store_init(&store);
    start();

    TEST_ASSERT_EQUAL_INT(LC_PROVISION_IDLE, state());
    TEST_ASSERT_FALSE(lc_provision_identity(&provision)->enrolled);
    TEST_ASSERT_EQUAL_STRING("", lc_provision_identity(&provision)->token);
    provoke_a_retry();
    TEST_ASSERT_EQUAL_UINT(0, gateway.subscribe_count);
    TEST_ASSERT_EQUAL_UINT(0, gateway.registration_count);
    TEST_ASSERT_EQUAL_UINT(0, store.save_count);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S3_H01_registration_is_published_only_after_the_subscription_is_confirmed);
    RUN_TEST(test_S3_H02_pending_flag_is_saved_before_the_registration_is_published);
    RUN_TEST(test_S3_H03_nothing_is_published_if_saving_the_pending_flag_fails);
    RUN_TEST(test_S3_H04_success_reply_stores_the_identity_in_one_write);
    RUN_TEST(test_S3_H05_rejected_or_expired_token_is_reported_and_not_retried);
    RUN_TEST(test_S3_H06_missing_reply_is_not_retried_and_leaves_the_pending_flag_set);
    RUN_TEST(test_a_reply_that_arrives_after_the_wait_still_enrolls_the_device);
    RUN_TEST(test_S3_H07_device_that_boots_with_the_pending_flag_never_registers);
    RUN_TEST(test_a_device_that_boots_with_the_pending_flag_waits_for_a_late_reply);
    RUN_TEST(test_S3_H08_malformed_and_misdirected_replies_are_ignored);
    RUN_TEST(test_S3_H09_device_is_not_enrolled_if_saving_the_credentials_fails);
    RUN_TEST(test_S3_H10_enrolled_device_never_publishes_a_registration);
    RUN_TEST(test_a_device_whose_identity_cannot_be_loaded_is_not_enrolled_and_never_registers);
    return UNITY_END();
}
