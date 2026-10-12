#include <stdio.h>
#include <string.h>

#include "fakes/fake_ports.h"
#include "lc_application/commission_device.h"
#include "unity.h"

#define LOCKER_UUID "11111111-1111-1111-1111-111111111111"

static fake_settings_store_t settings;
static fake_identity_store_t identity;
static fake_config_store_t config;
static fake_outbound_t outbound;
static fake_system_t device;
static lc_commission_t commission;
static unsigned stop_count;
static unsigned reply_count;
static lc_commission_result_t last_reply;

static void stop_commands(void *ctx)
{
    (void)ctx;
    fake_trace("commission.stop_commands");
    ++stop_count;
}

static void reply(void *ctx, lc_commission_result_t result)
{
    (void)ctx;
    fake_trace("commission.reply");
    ++reply_count;
    last_reply = result;
}

void setUp(void)
{
    fake_trace_reset();
    fake_settings_store_init(&settings);
    fake_identity_store_init(&identity);
    fake_config_store_init(&config);
    fake_outbound_init(&outbound);
    memset(&device, 0, sizeof(device));
    stop_count = 0;
    reply_count = 0;

    identity.has = true;
    snprintf(identity.identity.client_id, sizeof(identity.identity.client_id), "client-abc");

    const lc_commission_ports_t ports = {
        .settings_store = fake_settings_store_port(&settings),
        .identity_store = fake_identity_store_port(&identity),
        .config_store = fake_config_store_port(&config),
        .outbound = fake_outbound_port(&outbound),
        .system = fake_system_port(&device),
        .stop_commands = stop_commands,
        .reply = reply,
    };
    lc_commission_init(&commission, &ports);
}

void tearDown(void)
{
}

static lc_setup_request_t request(void)
{
    lc_setup_request_t setup;
    memset(&setup, 0, sizeof(setup));
    snprintf(setup.ssid, sizeof(setup.ssid), "workshop");
    snprintf(setup.wifi_password, sizeof(setup.wifi_password), "correct horse");
    snprintf(setup.broker_url, sizeof(setup.broker_url), "mqtts://broker.example:8883");
    snprintf(setup.bootstrap_user, sizeof(setup.bootstrap_user), "bootstrap");
    snprintf(setup.bootstrap_password, sizeof(setup.bootstrap_password), "bootstrap-secret");
    snprintf(setup.token, sizeof(setup.token), "one-time-token");
    return setup;
}

/* A device that has been enrolled and configured, and is online. */
static void enroll(void)
{
    identity.identity.enrolled = true;
    snprintf(identity.identity.mqtt_user, sizeof(identity.identity.mqtt_user), "issued-user");
    snprintf(identity.identity.mqtt_password, sizeof(identity.identity.mqtt_password),
             "issued-password");
    snprintf(identity.identity.locker_uuid, sizeof(identity.identity.locker_uuid), LOCKER_UUID);
    config.has = true;
    config.config.applied = true;
    config.config.count = 2;
    settings.has = true;
    settings.settings.configured = true;
    snprintf(settings.settings.ssid, sizeof(settings.settings.ssid), "old-network");
}

static void assert_nothing_stored(void)
{
    TEST_ASSERT_EQUAL_UINT(0, settings.save_count);
    TEST_ASSERT_EQUAL_UINT(0, identity.save_count);
    TEST_ASSERT_EQUAL_UINT(0, config.save_count);
    TEST_ASSERT_EQUAL_UINT(0, config.clear_count);
    TEST_ASSERT_EQUAL_UINT(0, settings.clear_count);
    TEST_ASSERT_EQUAL_UINT(0, device.restart_count);
}

void test_S5_H01_valid_settings_are_saved_then_acknowledged_then_the_restart_is_requested(void)
{
    const lc_setup_request_t setup = request();

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &setup));

    TEST_ASSERT_TRUE(settings.settings.configured);
    TEST_ASSERT_EQUAL_STRING("workshop", settings.settings.ssid);
    TEST_ASSERT_EQUAL_STRING("correct horse", settings.settings.wifi_password);
    TEST_ASSERT_EQUAL_STRING("mqtts://broker.example:8883", settings.settings.broker_url);
    TEST_ASSERT_EQUAL_STRING("bootstrap", identity.identity.bootstrap_user);
    TEST_ASSERT_EQUAL_STRING("bootstrap-secret", identity.identity.bootstrap_password);
    TEST_ASSERT_EQUAL_STRING("one-time-token", identity.identity.token);
    TEST_ASSERT_EQUAL_STRING("client-abc", identity.identity.client_id);
    TEST_ASSERT_FALSE(identity.identity.enrolled);

    TEST_ASSERT_EQUAL_UINT(1, reply_count);
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, last_reply);
    TEST_ASSERT_EQUAL_UINT(1, device.restart_count);
    TEST_ASSERT_TRUE(fake_trace_first("settings.save") >= 0);
    TEST_ASSERT_TRUE(fake_trace_first("identity.save") >= 0);
    TEST_ASSERT_TRUE(fake_trace_first("settings.save") < fake_trace_first("commission.reply"));
    TEST_ASSERT_TRUE(fake_trace_first("identity.save") < fake_trace_first("commission.reply"));
    TEST_ASSERT_TRUE(fake_trace_first("commission.reply") < fake_trace_first("system.restart"));
}

void test_S5_H02_invalid_settings_are_rejected_and_nothing_is_saved(void)
{
    lc_setup_request_t cases[8];
    for (size_t i = 0; i < sizeof(cases) / sizeof(cases[0]); ++i) {
        cases[i] = request();
    }
    cases[0].ssid[0] = '\0';
    snprintf(cases[1].wifi_password, sizeof(cases[1].wifi_password), "short");
    snprintf(cases[2].broker_url, sizeof(cases[2].broker_url), "mqtt://broker.example:1883");
    snprintf(cases[3].broker_url, sizeof(cases[3].broker_url), "https://broker.example");
    cases[4].broker_url[0] = '\0';
    snprintf(cases[5].broker_url, sizeof(cases[5].broker_url), "mqtts://");
    /* A device that is not enrolled needs all three enrollment values. */
    cases[6].token[0] = '\0';
    cases[7].bootstrap_user[0] = '\0';

    for (size_t i = 0; i < sizeof(cases) / sizeof(cases[0]); ++i) {
        TEST_ASSERT_EQUAL_INT(LC_COMMISSION_INVALID, lc_commission_apply(&commission, &cases[i]));
        TEST_ASSERT_EQUAL_INT(LC_COMMISSION_INVALID, last_reply);
        TEST_ASSERT_EQUAL_UINT(i + 1, reply_count);
    }

    assert_nothing_stored();
    TEST_ASSERT_EQUAL_UINT(0, stop_count);

    /* An open network is a valid choice. */
    lc_setup_request_t open_network = request();
    open_network.wifi_password[0] = '\0';
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &open_network));
}

void test_S5_H03_store_failure_produces_no_acknowledgement_and_no_restart(void)
{
    const lc_setup_request_t setup = request();

    settings.save_ok = false;
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVE_FAILED, lc_commission_apply(&commission, &setup));
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVE_FAILED, last_reply);
    TEST_ASSERT_EQUAL_UINT(0, device.restart_count);

    settings.save_ok = true;
    identity.save_ok = false;
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVE_FAILED, lc_commission_apply(&commission, &setup));
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVE_FAILED, last_reply);
    TEST_ASSERT_EQUAL_UINT(0, device.restart_count);
    TEST_ASSERT_FALSE(identity.identity.token[0] != '\0');
}

void test_S5_H04_network_change_on_an_enrolled_device_keeps_identity_and_mapping(void)
{
    enroll();
    const lc_setup_request_t setup = request();

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &setup));

    TEST_ASSERT_EQUAL_STRING("workshop", settings.settings.ssid);
    TEST_ASSERT_EQUAL_UINT(1, device.restart_count);
    /* Identity, mapping and journal are not touched at all. */
    TEST_ASSERT_EQUAL_UINT(0, identity.save_count);
    TEST_ASSERT_EQUAL_UINT(0, config.save_count);
    TEST_ASSERT_EQUAL_UINT(0, config.clear_count);
    TEST_ASSERT_TRUE(identity.identity.enrolled);
    TEST_ASSERT_EQUAL_STRING("issued-user", identity.identity.mqtt_user);
    TEST_ASSERT_EQUAL_STRING(LOCKER_UUID, identity.identity.locker_uuid);
    TEST_ASSERT_TRUE(config.has);
}

void test_S5_H05_enrollment_fields_are_ignored_on_an_enrolled_device(void)
{
    enroll();
    lc_setup_request_t setup = request();
    snprintf(setup.token, sizeof(setup.token), "another-token");

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &setup));

    TEST_ASSERT_EQUAL_STRING("", identity.identity.token);
    TEST_ASSERT_EQUAL_STRING("", identity.identity.bootstrap_user);
    TEST_ASSERT_EQUAL_STRING("", identity.identity.bootstrap_password);
    TEST_ASSERT_FALSE(identity.identity.enrollment_pending);

    /* And they are not required either. */
    lc_setup_request_t network_only;
    memset(&network_only, 0, sizeof(network_only));
    snprintf(network_only.ssid, sizeof(network_only.ssid), "third-network");
    snprintf(network_only.wifi_password, sizeof(network_only.wifi_password), "correct horse");
    snprintf(network_only.broker_url, sizeof(network_only.broker_url), "mqtts://broker.example");
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &network_only));
    TEST_ASSERT_EQUAL_STRING("third-network", settings.settings.ssid);
    TEST_ASSERT_EQUAL_UINT(0, identity.save_count);
}

void test_S5_H06_pending_enrollment_refuses_new_settings_until_reset(void)
{
    identity.identity.enrollment_pending = true;
    snprintf(identity.identity.token, sizeof(identity.identity.token), "spent-token");
    const lc_setup_request_t setup = request();

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_RESET_REQUIRED, lc_commission_apply(&commission, &setup));

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_RESET_REQUIRED, last_reply);
    assert_nothing_stored();
    TEST_ASSERT_EQUAL_STRING("spent-token", identity.identity.token);

    TEST_ASSERT_TRUE(lc_commission_reset(&commission));
    TEST_ASSERT_FALSE(identity.identity.enrollment_pending);
}

void test_S5_H07_reset_clears_identity_and_mapping_and_keeps_the_journal(void)
{
    enroll();

    TEST_ASSERT_TRUE(lc_commission_reset(&commission));

    TEST_ASSERT_FALSE(identity.identity.enrolled);
    TEST_ASSERT_FALSE(identity.identity.enrollment_pending);
    TEST_ASSERT_EQUAL_STRING("", identity.identity.mqtt_user);
    TEST_ASSERT_EQUAL_STRING("", identity.identity.mqtt_password);
    TEST_ASSERT_EQUAL_STRING("", identity.identity.locker_uuid);
    TEST_ASSERT_EQUAL_STRING("", identity.identity.token);
    /* The broker session identity is not reused; a new one is made at the next start. */
    TEST_ASSERT_EQUAL_STRING("", identity.identity.client_id);
    TEST_ASSERT_FALSE(config.has);
    TEST_ASSERT_FALSE(settings.has);
    TEST_ASSERT_EQUAL_UINT(1, device.restart_count);
    TEST_ASSERT_TRUE(fake_trace_first("identity.save") < fake_trace_first("system.restart"));
    TEST_ASSERT_TRUE(fake_trace_first("config.clear") < fake_trace_first("system.restart"));
    /* There is no journal port: a reset has no way to erase command history. */

    /* A reset that cannot be stored does not restart into a half-cleared state. */
    setUp();
    enroll();
    identity.save_ok = false;
    TEST_ASSERT_FALSE(lc_commission_reset(&commission));
    TEST_ASSERT_EQUAL_UINT(0, device.restart_count);
}

void test_S5_H08_offline_message_is_published_before_the_restart_when_online(void)
{
    enroll();
    lc_commission_set_online(&commission, true);
    const lc_setup_request_t setup = request();

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &setup));

    TEST_ASSERT_EQUAL_UINT(1, outbound.accepted[FAKE_OFFLINE]);
    TEST_ASSERT_EQUAL_INT(LC_OFFLINE_SHUTDOWN, outbound.offline_reason);
    TEST_ASSERT_TRUE(fake_trace_first("outbound.offline") < fake_trace_first("system.restart"));

    /* Offline, there is nobody to tell. */
    setUp();
    enroll();
    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &setup));
    TEST_ASSERT_EQUAL_UINT(0, outbound.attempts[FAKE_OFFLINE]);
    TEST_ASSERT_EQUAL_UINT(1, device.restart_count);

    /* A reset says goodbye as well. */
    setUp();
    enroll();
    lc_commission_set_online(&commission, true);
    TEST_ASSERT_TRUE(lc_commission_reset(&commission));
    TEST_ASSERT_EQUAL_UINT(1, outbound.accepted[FAKE_OFFLINE]);
    TEST_ASSERT_TRUE(fake_trace_first("outbound.offline") < fake_trace_first("system.restart"));
}

void test_S5_H09_new_commands_are_refused_from_the_moment_settings_are_accepted(void)
{
    enroll();
    const lc_setup_request_t setup = request();

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &setup));

    TEST_ASSERT_EQUAL_UINT(1, stop_count);
    TEST_ASSERT_TRUE(fake_trace_first("commission.stop_commands") <
                     fake_trace_first("settings.save"));

    setUp();
    enroll();
    TEST_ASSERT_TRUE(lc_commission_reset(&commission));
    TEST_ASSERT_EQUAL_UINT(1, stop_count);
    TEST_ASSERT_TRUE(fake_trace_first("commission.stop_commands") <
                     fake_trace_first("identity.save"));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S5_H01_valid_settings_are_saved_then_acknowledged_then_the_restart_is_requested);
    RUN_TEST(test_S5_H02_invalid_settings_are_rejected_and_nothing_is_saved);
    RUN_TEST(test_S5_H03_store_failure_produces_no_acknowledgement_and_no_restart);
    RUN_TEST(test_S5_H04_network_change_on_an_enrolled_device_keeps_identity_and_mapping);
    RUN_TEST(test_S5_H05_enrollment_fields_are_ignored_on_an_enrolled_device);
    RUN_TEST(test_S5_H06_pending_enrollment_refuses_new_settings_until_reset);
    RUN_TEST(test_S5_H07_reset_clears_identity_and_mapping_and_keeps_the_journal);
    RUN_TEST(test_S5_H08_offline_message_is_published_before_the_restart_when_online);
    RUN_TEST(test_S5_H09_new_commands_are_refused_from_the_moment_settings_are_accepted);
    return UNITY_END();
}
