#include <string.h>

#include "lc_usb_console/line.h"
#include "unity.h"

static lc_console_line_t input;
static unsigned ready_count;
static unsigned rejected_count;

void setUp(void)
{
    memset(&input, 0, sizeof(input));
    ready_count = 0;
    rejected_count = 0;
}

void tearDown(void)
{
}

static void feed(const char *text)
{
    for (const char *byte = text; *byte != '\0'; ++byte) {
        lc_console_feed_t result = lc_console_line_feed(&input, *byte);
        if (result == LC_CONSOLE_LINE_READY) {
            ++ready_count;
        } else if (result == LC_CONSOLE_LINE_REJECTED) {
            ++rejected_count;
        }
    }
}

static void assert_invalid(const char *line)
{
    TEST_ASSERT_EQUAL_INT_MESSAGE(LC_CONSOLE_INVALID, lc_console_parse(line).kind, line);
}

void test_S1_H25_unlock_and_query_parse_valid_arguments_and_reject_others(void)
{
    lc_console_command_t command = lc_console_parse("unlock 1 0");
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_UNLOCK, command.kind);
    TEST_ASSERT_EQUAL_UINT8(1, command.board);
    TEST_ASSERT_EQUAL_UINT8(0, command.address);

    command = lc_console_parse("unlock 31 254");
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_UNLOCK, command.kind);
    TEST_ASSERT_EQUAL_UINT8(31, command.board);
    TEST_ASSERT_EQUAL_UINT8(254, command.address);

    command = lc_console_parse("query 1");
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_QUERY, command.kind);
    TEST_ASSERT_EQUAL_UINT8(1, command.board);

    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_STATUS, lc_console_parse("status").kind);
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_DIAGNOSTICS, lc_console_parse("diagnostics").kind);
    TEST_ASSERT_EQUAL_INT(LC_CONSOLE_SELF_TEST, lc_console_parse("self-test").kind);

    assert_invalid("unlock 0 0");
    assert_invalid("unlock 32 0");
    assert_invalid("unlock 1 255");
    assert_invalid("unlock 1 -1");
    assert_invalid("unlock 1");
    assert_invalid("unlock");
    assert_invalid("unlock 1 0 0");
    assert_invalid("unlock one 0");
    assert_invalid("unlock 1 0x");
    assert_invalid("unlock 300 0");
    assert_invalid("unlock 4294967297 0");
    assert_invalid("query");
    assert_invalid("query 0");
    assert_invalid("query 32");
    assert_invalid("query 1 0");
    assert_invalid("status now");
    assert_invalid("open 1 0");
    assert_invalid("");
}

void test_S1_H26_oversized_line_is_discarded_through_its_newline(void)
{
    char oversized[LC_CONSOLE_LINE_MAX + 32];
    memset(oversized, 'a', sizeof(oversized));
    oversized[sizeof(oversized) - 1] = '\0';

    /* The tail of the oversized line is a valid command and must not run. */
    feed(oversized);
    feed(" unlock 1 0\n");

    TEST_ASSERT_EQUAL_UINT(0, ready_count);
    TEST_ASSERT_EQUAL_UINT(1, rejected_count);

    feed("status\r\n");

    TEST_ASSERT_EQUAL_UINT(1, ready_count);
    TEST_ASSERT_EQUAL_UINT(1, rejected_count);
    TEST_ASSERT_EQUAL_STRING("status", input.line);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S1_H25_unlock_and_query_parse_valid_arguments_and_reject_others);
    RUN_TEST(test_S1_H26_oversized_line_is_discarded_through_its_newline);
    return UNITY_END();
}
