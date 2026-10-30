#include <string.h>

#include "fakes/fake_serial.h"
#include "lc_rs485_lock_board/bus.h"
#include "lc_rs485_lock_board/codec.h"
#include "lc_usb_console/console.h"
#include "unity.h"

/* Console commands run against the real bus adapter over the scripted serial fake. */

#define REPLY_MS 500

static const lc_rs485_bus_config_t CONFIG = {
    .reply_deadline_ms = 1000,
    .quiet_interval_ms = 25,
    .settle_period_ms = 1500,
    .failures_before_cooldown = 3,
    .cooldown_ms = 60000,
};

static const uint8_t UNLOCK_BOARD_2_ADDRESS_4[] = {0x8A, 0x02, 0x05, 0x11, 0x9C};
static const uint8_t UNLOCK_BOARD_2_ADDRESS_4_ACK[] = {0x8A, 0x02, 0x05, 0x00, 0x8D};
/* Three status bytes; addresses 0 and 2 high. */
static const uint8_t STATUS_0_AND_2_HIGH[] = {0x80, 0x01, 0x00, 0x00, 0x05, 0x33, 0xB7};

static fake_serial_t serial;
static lc_rs485_bus_t bus;
static lc_console_t console;
static char replies[256];
static unsigned reply_count;
static bool self_test_result;

static void capture(void *ctx, const char *text)
{
    (void)ctx;
    TEST_ASSERT_TRUE(strlen(replies) + strlen(text) < sizeof(replies));
    strcat(replies, text);
    ++reply_count;
}

static uint32_t unlock_transmissions(void *ctx)
{
    (void)ctx;
    return lc_rs485_bus_unlock_transmissions(&bus);
}

static bool self_test(void *ctx)
{
    (void)ctx;
    return self_test_result;
}

void setUp(void)
{
    fake_serial_init(&serial);
    lc_rs485_bus_init(&bus, fake_serial_port(&serial), fake_serial_clock(&serial), &CONFIG);
    console = (lc_console_t){
        .bus = lc_rs485_bus_port(&bus),
        .write = capture,
        .unlock_transmissions = unlock_transmissions,
        .self_test = self_test,
    };
    replies[0] = '\0';
    reply_count = 0;
    self_test_result = true;
}

void tearDown(void)
{
}

void test_unlock_sends_one_frame_for_the_given_board_and_address(void)
{
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_BOARD_2_ADDRESS_4_ACK,
                               sizeof(UNLOCK_BOARD_2_ADDRESS_4_ACK));

    lc_console_execute(&console, "unlock 2 4");

    TEST_ASSERT_EQUAL_STRING("ok unlock acknowledged\n", replies);
    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
    TEST_ASSERT_EQUAL_HEX8_ARRAY(UNLOCK_BOARD_2_ADDRESS_4, fake_serial_write_at(&serial, 0)->data,
                                 sizeof(UNLOCK_BOARD_2_ADDRESS_4));
}

void test_unlock_without_a_reply_reports_outcome_unknown(void)
{
    lc_console_execute(&console, "unlock 2 4");

    TEST_ASSERT_EQUAL_STRING("error unlock outcome_unknown\n", replies);
    TEST_ASSERT_EQUAL_UINT(1, fake_serial_write_count(&serial));
}

void test_unlock_that_could_not_be_written_reports_not_sent(void)
{
    fake_serial_set_next_write_result(&serial, -1);

    lc_console_execute(&console, "unlock 2 4");

    TEST_ASSERT_EQUAL_STRING("error unlock not_sent\n", replies);
}

static bool device_enrolled;
static bool device_configured;

static bool provisioning_state(void *ctx, bool *enrolled, bool *pending, bool *configured)
{
    (void)ctx;
    *enrolled = device_enrolled;
    *pending = false;
    *configured = device_configured;
    return true;
}

void test_unlock_is_refused_once_the_device_is_enrolled_or_configured(void)
{
    console.provisioning_state = provisioning_state;

    device_enrolled = true;
    device_configured = false;
    lc_console_execute(&console, "unlock 2 4");
    TEST_ASSERT_EQUAL_STRING("error unlock disabled\n", replies);

    replies[0] = '\0';
    device_enrolled = false;
    device_configured = true;
    lc_console_execute(&console, "unlock 2 4");
    TEST_ASSERT_EQUAL_STRING("error unlock disabled\n", replies);

    TEST_ASSERT_EQUAL_UINT(0, fake_serial_write_count(&serial));
}

void test_query_prints_one_state_per_address(void)
{
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, STATUS_0_AND_2_HIGH,
                               sizeof(STATUS_0_AND_2_HIGH));

    lc_console_execute(&console, "query 1");

    TEST_ASSERT_EQUAL_STRING("ok doors=cocooooooooooooooooooooo????????\n", replies);
    TEST_ASSERT_EQUAL_UINT32(0, lc_rs485_bus_unlock_transmissions(&bus));
}

void test_query_without_a_reply_reports_an_error(void)
{
    lc_console_execute(&console, "query 1");

    TEST_ASSERT_EQUAL_STRING("error query no_valid_reply\n", replies);
}

void test_status_diagnostics_and_self_test_write_nothing_to_the_bus(void)
{
    lc_console_execute(&console, "status");
    lc_console_execute(&console, "diagnostics");
    lc_console_execute(&console, "self-test");
    self_test_result = false;
    lc_console_execute(&console, "self-test");

    TEST_ASSERT_EQUAL_STRING("ok bus=disconnected\n"
                             "ok bus=disconnected unlock_transmissions=0\n"
                             "ok self-test passed\n"
                             "error self-test failed\n",
                             replies);
    TEST_ASSERT_EQUAL_UINT(0, fake_serial_write_count(&serial));
}

void test_diagnostics_counts_unlock_transmissions_and_reports_the_bus_state(void)
{
    fake_serial_reply_to_write(&serial, 0, REPLY_MS, UNLOCK_BOARD_2_ADDRESS_4_ACK,
                               sizeof(UNLOCK_BOARD_2_ADDRESS_4_ACK));
    lc_console_execute(&console, "unlock 2 4");
    replies[0] = '\0';

    lc_console_execute(&console, "diagnostics");
    TEST_ASSERT_EQUAL_STRING("ok bus=connected unlock_transmissions=1\n", replies);

    for (unsigned i = 0; i < CONFIG.failures_before_cooldown; ++i) {
        lc_console_execute(&console, "query 1");
    }
    replies[0] = '\0';

    lc_console_execute(&console, "status");
    TEST_ASSERT_EQUAL_STRING("ok bus=unreachable\n", replies);
}

void test_invalid_and_rejected_lines_get_one_error_reply_and_write_nothing(void)
{
    lc_console_execute(&console, "unlock 1 255");
    lc_console_execute(&console, "open 1 0");
    lc_console_reject(&console);

    TEST_ASSERT_EQUAL_STRING("error invalid command\n"
                             "error invalid command\n"
                             "error line too long\n",
                             replies);
    TEST_ASSERT_EQUAL_UINT(3, reply_count);
    TEST_ASSERT_EQUAL_UINT(0, fake_serial_write_count(&serial));
}

void test_codec_self_test_passes(void)
{
    TEST_ASSERT_TRUE(lc_rs485_codec_self_test());
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_unlock_sends_one_frame_for_the_given_board_and_address);
    RUN_TEST(test_unlock_without_a_reply_reports_outcome_unknown);
    RUN_TEST(test_unlock_that_could_not_be_written_reports_not_sent);
    RUN_TEST(test_unlock_is_refused_once_the_device_is_enrolled_or_configured);
    RUN_TEST(test_query_prints_one_state_per_address);
    RUN_TEST(test_query_without_a_reply_reports_an_error);
    RUN_TEST(test_status_diagnostics_and_self_test_write_nothing_to_the_bus);
    RUN_TEST(test_diagnostics_counts_unlock_transmissions_and_reports_the_bus_state);
    RUN_TEST(test_invalid_and_rejected_lines_get_one_error_reply_and_write_nothing);
    RUN_TEST(test_codec_self_test_passes);
    return UNITY_END();
}
