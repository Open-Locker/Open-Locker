#include <stdbool.h>

#include "lc_softap_portal/portal.h"
#include "unity.h"

#define T0 5000

static lc_portal_t portal;

void setUp(void)
{
    lc_portal_init(&portal);
    lc_portal_open(&portal, T0);
}

void tearDown(void)
{
}

static void authenticated_station(void)
{
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
    lc_portal_session_established(&portal);
}

void test_S6_H03_a_failed_session_reaches_no_endpoint_and_five_failures_close_the_window(void)
{
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
    /* Before the handshake only the handshake itself is reachable. */
    TEST_ASSERT_TRUE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_SESSION));
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_WIFI_CONFIG));

    for (unsigned failure = 1; failure < LC_PORTAL_MAX_FAILED_SESSIONS; ++failure) {
        lc_portal_session_failed(&portal);
        TEST_ASSERT_TRUE(lc_portal_is_open(&portal));
        TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));
        TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_WIFI_CONFIG));
    }
    lc_portal_session_failed(&portal);

    TEST_ASSERT_FALSE(lc_portal_is_open(&portal));
    TEST_ASSERT_EQUAL_INT(LC_PORTAL_CLOSED_TOO_MANY_FAILURES, portal.state);
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_SESSION));
    TEST_ASSERT_FALSE(lc_portal_station_joined(&portal));

    /* A failure after an established session takes the session away again. */
    setUp();
    authenticated_station();
    TEST_ASSERT_TRUE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));
    lc_portal_session_failed(&portal);
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));

    /* Failures count over the whole window, not only in a row. */
    setUp();
    for (unsigned round = 0; round < LC_PORTAL_MAX_FAILED_SESSIONS - 1; ++round) {
        authenticated_station();
        lc_portal_station_left(&portal);
        TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
        lc_portal_session_failed(&portal);
        lc_portal_station_left(&portal);
    }
    TEST_ASSERT_TRUE(lc_portal_is_open(&portal));
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
    lc_portal_session_failed(&portal);
    TEST_ASSERT_FALSE(lc_portal_is_open(&portal));
}

void test_S6_H07_the_portal_closes_after_saving_and_after_10_minutes_and_a_new_boot_reopens_it(void)
{
    authenticated_station();
    lc_portal_saved(&portal);
    TEST_ASSERT_EQUAL_INT(LC_PORTAL_CLOSED_SAVED, portal.state);
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));

    setUp();
    TEST_ASSERT_TRUE(lc_portal_tick(&portal, T0 + LC_PORTAL_WINDOW_MS - 1));
    TEST_ASSERT_FALSE(lc_portal_tick(&portal, T0 + LC_PORTAL_WINDOW_MS));
    TEST_ASSERT_EQUAL_INT(LC_PORTAL_CLOSED_TIMEOUT, portal.state);
    TEST_ASSERT_FALSE(lc_portal_station_joined(&portal));

    /* A unit with no settings opens it again at the next power-up: a new run, a new portal. */
    lc_portal_init(&portal);
    lc_portal_open(&portal, 0);
    TEST_ASSERT_TRUE(lc_portal_is_open(&portal));
}

void test_S6_H08_nothing_reopens_a_closed_portal_during_the_run(void)
{
    authenticated_station();
    lc_portal_saved(&portal);

    /* The unit now fails to join the Wi-Fi and retries for as long as it likes. */
    for (int attempt = 0; attempt < 50; ++attempt) {
        lc_portal_open(&portal, T0 + attempt * 1000);
        (void)lc_portal_tick(&portal, T0 + attempt * 1000);
        (void)lc_portal_station_joined(&portal);
        lc_portal_station_left(&portal);
        lc_portal_session_established(&portal);
    }
    TEST_ASSERT_FALSE(lc_portal_is_open(&portal));
    TEST_ASSERT_EQUAL_INT(LC_PORTAL_CLOSED_SAVED, portal.state);

    /* A portal that never opened stays shut too. */
    lc_portal_init(&portal);
    TEST_ASSERT_FALSE(lc_portal_station_joined(&portal));
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_SESSION));
}

void test_S6_H09_the_portal_exposes_no_status_diagnostics_or_stored_settings(void)
{
    authenticated_station();

    TEST_ASSERT_TRUE(lc_portal_endpoint_name_allowed(&portal, "prov-session"));
    TEST_ASSERT_TRUE(lc_portal_endpoint_name_allowed(&portal, "prov-config"));
    TEST_ASSERT_TRUE(lc_portal_endpoint_name_allowed(&portal, "ol-config"));

    /* Even with an authenticated session: scan results, version info, anything else. */
    const char *const others[] = {"prov-scan", "proto-ver", "custom-data", "status",
                                  "diagnostics", "settings", "", "ol-config2", "OL-CONFIG"};
    for (unsigned i = 0; i < sizeof(others) / sizeof(others[0]); ++i) {
        TEST_ASSERT_FALSE_MESSAGE(lc_portal_endpoint_name_allowed(&portal, others[i]), others[i]);
    }
}

void test_S6_H13_a_second_station_is_refused_while_one_is_connected(void)
{
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
    TEST_ASSERT_FALSE(lc_portal_station_joined(&portal));
    TEST_ASSERT_FALSE(lc_portal_station_joined(&portal));

    lc_portal_station_left(&portal);
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));

    /* The new station does not inherit the session of the one before. */
    setUp();
    authenticated_station();
    lc_portal_station_left(&portal);
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));

    /* A session cannot be established with no station. */
    setUp();
    lc_portal_session_established(&portal);
    TEST_ASSERT_TRUE(lc_portal_station_joined(&portal));
    TEST_ASSERT_FALSE(lc_portal_endpoint_allowed(&portal, LC_PORTAL_ENDPOINT_CONFIG));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S6_H03_a_failed_session_reaches_no_endpoint_and_five_failures_close_the_window);
    RUN_TEST(test_S6_H07_the_portal_closes_after_saving_and_after_10_minutes_and_a_new_boot_reopens_it);
    RUN_TEST(test_S6_H08_nothing_reopens_a_closed_portal_during_the_run);
    RUN_TEST(test_S6_H09_the_portal_exposes_no_status_diagnostics_or_stored_settings);
    RUN_TEST(test_S6_H13_a_second_station_is_refused_while_one_is_connected);
    return UNITY_END();
}
