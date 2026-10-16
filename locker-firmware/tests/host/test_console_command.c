#include <string.h>

#include "fakes/fake_ports.h"
#include "lc_usb_console/console.h"
#include "lc_usb_console/line.h"
#include "unity.h"

/*
 * The `command` line of the console, which feeds one MQTT command payload to
 * the application, as the hardware checks of Group B do. Not in the test
 * catalogue.
 */

#define PAYLOAD                                                                              \
    "{\"message_id\":\"m1\",\"transaction_id\":\"t1\",\"action\":\"open_compartment\","     \
    "\"timestamp\":\"2026-04-14T19:30:00Z\",\"data\":{\"compartment_number\":3}}"

static fake_bus_t bus;
static lc_console_t console;
static char replies[512];
static char submitted[LC_CONSOLE_LINE_MAX + 1];
static unsigned submit_count;
static bool accept;

static void capture(void *ctx, const char *text)
{
    (void)ctx;
    TEST_ASSERT_TRUE(strlen(replies) + strlen(text) < sizeof(replies));
    strcat(replies, text);
}

static uint32_t unlock_transmissions(void *ctx)
{
    (void)ctx;
    return 0;
}

static bool self_test(void *ctx)
{
    (void)ctx;
    return true;
}

static bool submit_command(void *ctx, const char *json)
{
    (void)ctx;
    ++submit_count;
    TEST_ASSERT_TRUE(strlen(json) < sizeof(submitted));
    strcpy(submitted, json);
    return accept;
}

void setUp(void)
{
    fake_bus_init(&bus);
    console = (lc_console_t){
        .bus = fake_bus_port(&bus),
        .write = capture,
        .unlock_transmissions = unlock_transmissions,
        .self_test = self_test,
        .submit_command = submit_command,
    };
    replies[0] = '\0';
    submitted[0] = '\0';
    submit_count = 0;
    accept = true;
}

void tearDown(void)
{
}

void test_command_line_hands_the_rest_of_the_line_over(void)
{
    lc_console_command_t parsed = lc_console_parse("command " PAYLOAD);

    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_COMMAND, parsed.kind);
    TEST_ASSERT_EQUAL_STRING(PAYLOAD, parsed.argument);
    /* Spaces inside the payload and around the word are kept or skipped as written. */
    parsed = lc_console_parse("  command   {\"a\": 1}");
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_COMMAND, parsed.kind);
    TEST_ASSERT_EQUAL_STRING("{\"a\": 1}", parsed.argument);

    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_INVALID, lc_console_parse("command").kind);
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_INVALID, lc_console_parse("command   ").kind);
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_INVALID, lc_console_parse("commands {}").kind);
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_INVALID, lc_console_parse("Command {}").kind);
}

void test_a_line_with_a_whole_command_fits(void)
{
    TEST_ASSERT_TRUE(sizeof("command " PAYLOAD) - 1 <= LC_CONSOLE_LINE_MAX);
}

void test_the_payload_is_submitted_and_never_echoed(void)
{
    lc_console_execute(&console, "command " PAYLOAD);

    TEST_ASSERT_EQUAL_UINT(1, submit_count);
    TEST_ASSERT_EQUAL_STRING(PAYLOAD, submitted);
    TEST_ASSERT_EQUAL_STRING("ok command queued\n", replies);
    TEST_ASSERT_NULL(strstr(replies, "t1"));
}

void test_a_command_that_was_not_accepted_gets_an_error(void)
{
    accept = false;

    lc_console_execute(&console, "command " PAYLOAD);

    TEST_ASSERT_EQUAL_STRING("error command not accepted\n", replies);
}

void test_without_a_handler_the_command_is_refused(void)
{
    console.submit_command = NULL;

    lc_console_execute(&console, "command " PAYLOAD);

    TEST_ASSERT_EQUAL_UINT(0, submit_count);
    TEST_ASSERT_EQUAL_STRING("error command not accepted\n", replies);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_command_line_hands_the_rest_of_the_line_over);
    RUN_TEST(test_a_line_with_a_whole_command_fits);
    RUN_TEST(test_the_payload_is_submitted_and_never_echoed);
    RUN_TEST(test_a_command_that_was_not_accepted_gets_an_error);
    RUN_TEST(test_without_a_handler_the_command_is_refused);
    return UNITY_END();
}
