#include <stdint.h>
#include <stdio.h>
#include <string.h>

#include "fakes/fake_ports.h"
#include "lc_application/commission_device.h"
#include "lc_softap_portal/ol_config.h"
#include "lc_usb_console/setup_json.h"
#include "unity.h"

#define SSID "workshop"
#define WIFI_PASSWORD "correct horse"
#define BROKER "mqtts://broker.example:8883"
#define BOOTSTRAP_USER "bootstrap"
#define BOOTSTRAP_PASSWORD "bootstrap-secret"
#define TOKEN "one-time-token"

static fake_settings_store_t settings;
static fake_identity_store_t identity;
static fake_config_store_t config;
static fake_outbound_t outbound;
static fake_system_t device;
static lc_commission_t commission;
static unsigned reply_count;
static lc_commission_result_t last_reply;

static void stop_commands(void *ctx)
{
    (void)ctx;
}

static void reply(void *ctx, lc_commission_result_t result)
{
    (void)ctx;
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

static size_t add_item(uint8_t *out, size_t at, uint8_t tag, const char *value)
{
    size_t length = strlen(value);
    out[at++] = tag;
    out[at++] = (uint8_t)length;
    memcpy(out + at, value, length);
    return at + length;
}

/* A valid payload; `skip` leaves one tag out. */
static size_t payload(uint8_t *out, uint8_t skip)
{
    size_t at = 0;
    out[at++] = LC_OL_CONFIG_VERSION;
    if (skip != 1) at = add_item(out, at, 1, BROKER);
    if (skip != 2) at = add_item(out, at, 2, BOOTSTRAP_USER);
    if (skip != 3) at = add_item(out, at, 3, BOOTSTRAP_PASSWORD);
    if (skip != 4) at = add_item(out, at, 4, TOKEN);
    return at;
}

static bool collect(lc_collector_t *collector, const uint8_t *bytes, size_t length)
{
    return lc_collector_wifi(collector, (const uint8_t *)SSID, strlen(SSID),
                             (const uint8_t *)WIFI_PASSWORD, strlen(WIFI_PASSWORD)) &&
           lc_collector_ol_config(collector, bytes, length);
}

static void assert_nothing_stored(void)
{
    TEST_ASSERT_EQUAL_UINT(0, settings.save_count);
    TEST_ASSERT_EQUAL_UINT(0, identity.save_count);
    TEST_ASSERT_EQUAL_UINT(0, device.restart_count);
}

void test_S6_H01_a_valid_submission_reaches_commissioning_with_the_values_of_a_usb_one(void)
{
    uint8_t bytes[LC_OL_CONFIG_MAX];
    size_t length = payload(bytes, 0);
    lc_collector_t collector;
    lc_collector_init(&collector);
    TEST_ASSERT_FALSE(lc_collector_ready(&collector));
    TEST_ASSERT_TRUE(collect(&collector, bytes, length));
    TEST_ASSERT_TRUE(lc_collector_ready(&collector));

    lc_setup_request_t from_portal;
    TEST_ASSERT_TRUE(lc_collector_take(&collector, &from_portal));
    TEST_ASSERT_FALSE(lc_collector_ready(&collector));

    lc_setup_request_t from_usb;
    TEST_ASSERT_TRUE(lc_console_parse_setup(
        "{\"ssid\":\"" SSID "\",\"wifi_password\":\"" WIFI_PASSWORD "\",\"broker\":\"" BROKER
        "\",\"bootstrap_user\":\"" BOOTSTRAP_USER "\",\"bootstrap_password\":\"" BOOTSTRAP_PASSWORD
        "\",\"token\":\"" TOKEN "\"}",
        &from_usb));
    TEST_ASSERT_EQUAL_MEMORY(&from_usb, &from_portal, sizeof(from_usb));

    TEST_ASSERT_EQUAL_INT(LC_COMMISSION_SAVED, lc_commission_apply(&commission, &from_portal));
    TEST_ASSERT_EQUAL_STRING(SSID, settings.settings.ssid);
    TEST_ASSERT_EQUAL_STRING(WIFI_PASSWORD, settings.settings.wifi_password);
    TEST_ASSERT_EQUAL_STRING(BROKER, settings.settings.broker_url);
    TEST_ASSERT_EQUAL_STRING(BOOTSTRAP_USER, identity.identity.bootstrap_user);
    TEST_ASSERT_EQUAL_STRING(BOOTSTRAP_PASSWORD, identity.identity.bootstrap_password);
    TEST_ASSERT_EQUAL_STRING(TOKEN, identity.identity.token);
    TEST_ASSERT_EQUAL_UINT(1, device.restart_count);
}

void test_S6_H02_an_oversized_incomplete_or_malformed_submission_is_refused_and_nothing_is_saved(void)
{
    uint8_t good[LC_OL_CONFIG_MAX];
    size_t good_length = payload(good, 0);
    lc_collector_t collector;
    lc_setup_request_t out;

    /* Each of these is refused, and the collector never becomes ready. */
    uint8_t bad[LC_OL_CONFIG_MAX + 64];
    size_t bad_length;

    /* A missing field, for each of the four. */
    for (uint8_t skip = 1; skip <= 4; ++skip) {
        lc_collector_init(&collector);
        bad_length = payload(bad, skip);
        TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, bad_length));
        TEST_ASSERT_FALSE(lc_collector_ready(&collector));
    }

    /* Empty, wrong version, unknown tag, repeated tag. */
    lc_collector_init(&collector);
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, good, 0));
    memcpy(bad, good, good_length);
    bad[0] = 2;
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, good_length));
    memcpy(bad, good, good_length);
    bad[1] = 9;
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, good_length));
    memcpy(bad, good, good_length);
    bad_length = add_item(bad, good_length, 4, "again");
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, bad_length));

    /* A length past the end, a cut header, a byte left over, a NUL inside a value. */
    memcpy(bad, good, good_length);
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, good_length - 1));
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, good_length - 3 - (int)strlen(TOKEN) + 1));
    bad[good_length] = 0x04;
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, good_length + 1));
    memcpy(bad, good, good_length);
    bad[4] = '\0'; /* Inside the broker URL. */
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, good_length));

    /* A value that does not fit its field, and a payload over the limit. */
    size_t at = 0;
    bad[at++] = LC_OL_CONFIG_VERSION;
    bad[at++] = 4;
    bad[at++] = LC_CREDENTIAL_MAX + 1;
    memset(bad + at, 'x', LC_CREDENTIAL_MAX + 1);
    at += LC_CREDENTIAL_MAX + 1;
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, at));
    memset(bad, 'x', sizeof(bad));
    bad[0] = LC_OL_CONFIG_VERSION;
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, bad, LC_OL_CONFIG_MAX + 1));

    /* A refused payload leaves none of its values behind, and nothing is ever handed on. */
    TEST_ASSERT_FALSE(lc_collector_ready(&collector));
    TEST_ASSERT_FALSE(lc_collector_take(&collector, &out));
    lc_setup_request_t zero;
    memset(&zero, 0, sizeof(zero));
    TEST_ASSERT_EQUAL_MEMORY(&zero, &out, sizeof(out));
    TEST_ASSERT_EQUAL_STRING("", collector.request.token);
    TEST_ASSERT_EQUAL_STRING("", collector.request.broker_url);

    /* Wi-Fi settings that do not fit or hold a NUL are refused too. */
    lc_collector_init(&collector);
    uint8_t long_ssid[LC_SSID_MAX + 1];
    memset(long_ssid, 'a', sizeof(long_ssid));
    TEST_ASSERT_FALSE(lc_collector_wifi(&collector, long_ssid, sizeof(long_ssid),
                                        (const uint8_t *)WIFI_PASSWORD, strlen(WIFI_PASSWORD)));
    TEST_ASSERT_FALSE(lc_collector_wifi(&collector, (const uint8_t *)"a\0b", 3,
                                        (const uint8_t *)WIFI_PASSWORD, strlen(WIFI_PASSWORD)));
    TEST_ASSERT_FALSE(lc_collector_wifi(&collector, (const uint8_t *)SSID, strlen(SSID),
                                        (const uint8_t *)"pass\0word", 9));
    TEST_ASSERT_FALSE(lc_collector_ready(&collector));
    TEST_ASSERT_EQUAL_STRING("", collector.request.ssid);

    /* A good half is not enough: with only one part in, nothing can be taken. */
    TEST_ASSERT_TRUE(lc_collector_ol_config(&collector, good, good_length));
    TEST_ASSERT_FALSE(lc_collector_ready(&collector));
    TEST_ASSERT_FALSE(lc_collector_take(&collector, &out));

    /* A refused second submission takes the first one's config with it. */
    lc_collector_init(&collector);
    TEST_ASSERT_TRUE(collect(&collector, good, good_length));
    TEST_ASSERT_FALSE(lc_collector_ol_config(&collector, good, good_length - 1));
    TEST_ASSERT_FALSE(lc_collector_ready(&collector));

    assert_nothing_stored();
}

void test_S6_H04_no_response_contains_a_submitted_value(void)
{
    uint8_t reply_bytes[4];
    for (int accepted = 0; accepted <= 1; ++accepted) {
        memset(reply_bytes, 0xAA, sizeof(reply_bytes));
        TEST_ASSERT_EQUAL_UINT(1, lc_ol_config_reply(accepted != 0, reply_bytes));
        TEST_ASSERT_TRUE(reply_bytes[0] == LC_OL_CONFIG_REPLY_OK ||
                         reply_bytes[0] == LC_OL_CONFIG_REPLY_REFUSED);
        TEST_ASSERT_EQUAL_UINT8(0xAA, reply_bytes[1]); /* Nothing else is written. */
    }

    /* Every refusal looks the same, whatever was wrong with the input. */
    uint8_t good[LC_OL_CONFIG_MAX];
    size_t length = payload(good, 0);
    lc_collector_t collector;
    lc_collector_init(&collector);
    uint8_t first = 0xFF;
    for (size_t cut = 0; cut < length; ++cut) {
        bool accepted = lc_collector_ol_config(&collector, good, cut);
        TEST_ASSERT_FALSE(accepted);
        uint8_t byte;
        (void)lc_ol_config_reply(accepted, &byte);
        if (first == 0xFF) {
            first = byte;
        }
        TEST_ASSERT_EQUAL_UINT8(first, byte);
    }
}

/* A fixed-seed stream of garbage and mutated payloads: no crash, no overflow, nothing half-taken. */
void test_ol_config_survives_random_and_mutated_input(void)
{
    uint8_t good[LC_OL_CONFIG_MAX];
    size_t good_length = payload(good, 0);
    uint32_t state = 0x9E3779B9u;
    uint8_t bytes[LC_OL_CONFIG_MAX + 16];
    lc_collector_t collector;

    for (unsigned round = 0; round < 50000; ++round) {
        state ^= state << 13;
        state ^= state >> 17;
        state ^= state << 5;
        size_t length;
        if (round % 2 == 0) {
            length = state % (sizeof(bytes) + 1);
            for (size_t i = 0; i < length; ++i) {
                state ^= state << 13;
                state ^= state >> 17;
                state ^= state << 5;
                bytes[i] = (uint8_t)state;
            }
        } else {
            memcpy(bytes, good, good_length);
            length = good_length;
            for (unsigned flips = 1 + state % 4; flips > 0; --flips) {
                state ^= state << 13;
                state ^= state >> 17;
                state ^= state << 5;
                bytes[state % length] = (uint8_t)(state >> 8);
            }
        }
        lc_collector_init(&collector);
        bool accepted = lc_collector_ol_config(&collector, bytes, length);
        if (!accepted) {
            TEST_ASSERT_EQUAL_STRING("", collector.request.token);
            TEST_ASSERT_EQUAL_STRING("", collector.request.broker_url);
        } else {
            TEST_ASSERT_TRUE(strlen(collector.request.token) > 0);
        }
    }
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S6_H01_a_valid_submission_reaches_commissioning_with_the_values_of_a_usb_one);
    RUN_TEST(test_S6_H02_an_oversized_incomplete_or_malformed_submission_is_refused_and_nothing_is_saved);
    RUN_TEST(test_S6_H04_no_response_contains_a_submitted_value);
    RUN_TEST(test_ol_config_survives_random_and_mutated_input);
    return UNITY_END();
}
