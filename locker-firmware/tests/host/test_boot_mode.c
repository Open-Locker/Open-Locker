#include <stdio.h>
#include <string.h>

#include "fakes/fake_ports.h"
#include "lc_application/boot_mode.h"
#include "unity.h"

static fake_settings_store_t settings;
static fake_identity_store_t identity;
static fake_config_store_t config;
static lc_boot_ports_t ports;

void setUp(void)
{
    fake_trace_reset();
    fake_settings_store_init(&settings);
    fake_identity_store_init(&identity);
    fake_config_store_init(&config);
    /* Every start makes a broker session identity; it must not count as an identity. */
    identity.has = true;
    snprintf(identity.identity.client_id, sizeof(identity.identity.client_id), "client-abc");
    ports.settings_store = fake_settings_store_port(&settings);
    ports.identity_store = fake_identity_store_port(&identity);
    ports.config_store = fake_config_store_port(&config);
}

void tearDown(void)
{
}

static void store_settings(void)
{
    settings.has = true;
    settings.settings.configured = true;
    snprintf(settings.settings.ssid, sizeof(settings.settings.ssid), "workshop");
}

static void store_mapping(void)
{
    config.has = true;
    config.config.applied = true;
    config.config.count = 2;
}

static void store_token(void)
{
    snprintf(identity.identity.token, sizeof(identity.identity.token), "one-time-token");
}

static void enroll(void)
{
    identity.identity.enrolled = true;
    snprintf(identity.identity.mqtt_user, sizeof(identity.identity.mqtt_user), "issued-user");
}

void test_S6_H05_the_setup_network_starts_when_no_settings_are_stored(void)
{
    TEST_ASSERT_EQUAL_INT(LC_BOOT_SETUP, lc_boot_decide(&ports).mode);

    /* A blank flash, with no identity at all, is the same. */
    identity.has = false;
    TEST_ASSERT_EQUAL_INT(LC_BOOT_SETUP, lc_boot_decide(&ports).mode);

    /* A bench mapping does not change that, and is not touched (S6-H26). */
    store_mapping();
    TEST_ASSERT_EQUAL_INT(LC_BOOT_SETUP, lc_boot_decide(&ports).mode);
}

void test_S6_H06_the_setup_network_never_starts_with_settings_or_on_an_enrolled_unit(void)
{
    /* Settings with a token: a unit waiting to enroll. */
    store_settings();
    store_token();
    lc_boot_result_t result = lc_boot_decide(&ports);
    TEST_ASSERT_EQUAL_INT(LC_BOOT_STATION, result.mode);
    TEST_ASSERT_FALSE(result.repaired);

    /* Enrolled, with settings. */
    setUp();
    store_settings();
    enroll();
    store_mapping();
    TEST_ASSERT_EQUAL_INT(LC_BOOT_STATION, lc_boot_decide(&ports).mode);

    /* Enrolled and the settings are gone: the console only, never the setup network. */
    setUp();
    enroll();
    TEST_ASSERT_EQUAL_INT(LC_BOOT_BENCH, lc_boot_decide(&ports).mode);

    /* A pending enrollment with settings is a station too. */
    setUp();
    store_settings();
    identity.identity.enrollment_pending = true;
    TEST_ASSERT_EQUAL_INT(LC_BOOT_STATION, lc_boot_decide(&ports).mode);

    TEST_ASSERT_EQUAL_UINT(0, settings.clear_count);
    TEST_ASSERT_EQUAL_UINT(0, config.clear_count);
}

void test_S6_H24_an_empty_identity_with_settings_finishes_the_wipe_then_decides_the_mode(void)
{
    store_settings();
    store_mapping();

    lc_boot_result_t result = lc_boot_decide(&ports);

    TEST_ASSERT_TRUE(result.repaired);
    TEST_ASSERT_FALSE(result.repair_failed);
    TEST_ASSERT_FALSE(settings.has);
    TEST_ASSERT_FALSE(config.has);
    TEST_ASSERT_EQUAL_INT(LC_BOOT_SETUP, result.mode);

    /* A clear that fails is reported, and the unit stays a station until the next try. */
    setUp();
    store_settings();
    store_mapping();
    settings.clear_ok = false;
    result = lc_boot_decide(&ports);
    TEST_ASSERT_TRUE(result.repaired);
    TEST_ASSERT_TRUE(result.repair_failed);
    TEST_ASSERT_EQUAL_UINT(1, config.clear_count);
    TEST_ASSERT_EQUAL_INT(LC_BOOT_STATION, result.mode);

    /* A mapping that cannot be cleared is reported too; the settings still go. */
    setUp();
    store_settings();
    store_mapping();
    config.clear_ok = false;
    result = lc_boot_decide(&ports);
    TEST_ASSERT_TRUE(result.repair_failed);
    TEST_ASSERT_FALSE(settings.has);
    TEST_ASSERT_EQUAL_INT(LC_BOOT_SETUP, result.mode);
}

void test_S6_H26_an_empty_identity_with_a_mapping_and_no_settings_leaves_the_mapping_alone(void)
{
    store_mapping();

    lc_boot_result_t result = lc_boot_decide(&ports);

    TEST_ASSERT_FALSE(result.repaired);
    TEST_ASSERT_TRUE(config.has);
    TEST_ASSERT_EQUAL_UINT(0, config.clear_count);
    TEST_ASSERT_EQUAL_UINT(0, settings.clear_count);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S6_H05_the_setup_network_starts_when_no_settings_are_stored);
    RUN_TEST(test_S6_H06_the_setup_network_never_starts_with_settings_or_on_an_enrolled_unit);
    RUN_TEST(test_S6_H24_an_empty_identity_with_settings_finishes_the_wipe_then_decides_the_mode);
    RUN_TEST(test_S6_H26_an_empty_identity_with_a_mapping_and_no_settings_leaves_the_mapping_alone);
    return UNITY_END();
}
