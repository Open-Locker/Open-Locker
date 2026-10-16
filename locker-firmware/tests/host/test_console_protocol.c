#include <stdio.h>
#include <string.h>

#include "fakes/fake_ports.h"
#include "lc_usb_console/console.h"
#include "lc_usb_console/line.h"
#include "unity.h"

/* The console as a commissioning channel: bounded input, no secrets in replies. */

#define SSID "S3CRET-SSID"
#define WIFI_PASSWORD "S3CRET-WIFI-PASSWORD"
#define BROKER "mqtts://s3cret-broker.example:8883"
#define BOOTSTRAP_USER "S3CRET-BOOTSTRAP-USER"
#define BOOTSTRAP_PASSWORD "S3CRET-BOOTSTRAP-PASSWORD"
#define TOKEN "S3CRET-ONE-TIME-TOKEN"

static const char CONFIGURE[] =
    "configure {\"ssid\":\"" SSID "\",\"wifi_password\":\"" WIFI_PASSWORD "\",\"broker\":\"" BROKER
    "\",\"bootstrap_user\":\"" BOOTSTRAP_USER "\",\"bootstrap_password\":\"" BOOTSTRAP_PASSWORD
    "\",\"token\":\"" TOKEN "\"}";

static fake_bus_t bus;
static lc_console_t console;
static lc_console_line_t input;
static char replies[4096];
static unsigned reply_count;
static unsigned ready_count;
static unsigned rejected_count;
static unsigned setup_count;
static unsigned reset_count;
static lc_setup_request_t submitted;

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
    return 0;
}

static bool self_test(void *ctx)
{
    (void)ctx;
    return true;
}

static bool submit_setup(void *ctx, const lc_setup_request_t *request)
{
    (void)ctx;
    ++setup_count;
    submitted = *request;
    return true;
}

static bool request_reset(void *ctx)
{
    (void)ctx;
    ++reset_count;
    return true;
}

void setUp(void)
{
    fake_trace_reset();
    fake_bus_init(&bus);
    console = (lc_console_t){
        .bus = fake_bus_port(&bus),
        .write = capture,
        .unlock_transmissions = unlock_transmissions,
        .self_test = self_test,
        .submit_setup = submit_setup,
        .request_reset = request_reset,
    };
    memset(&input, 0, sizeof(input));
    memset(&submitted, 0, sizeof(submitted));
    replies[0] = '\0';
    reply_count = 0;
    ready_count = 0;
    rejected_count = 0;
    setup_count = 0;
    reset_count = 0;
}

void tearDown(void)
{
}

static void feed(const char *bytes, size_t length)
{
    for (size_t i = 0; i < length; ++i) {
        lc_console_feed_t result = lc_console_line_feed(&input, bytes[i]);
        if (result == LC_CONSOLE_LINE_READY) {
            ++ready_count;
        } else if (result == LC_CONSOLE_LINE_REJECTED) {
            ++rejected_count;
        }
    }
}

static void feed_text(const char *text)
{
    feed(text, strlen(text));
}

static void assert_nothing_happened(void)
{
    TEST_ASSERT_EQUAL_UINT(0, setup_count);
    TEST_ASSERT_EQUAL_UINT(0, reset_count);
    TEST_ASSERT_EQUAL_UINT(0, bus.unlock_count);
    TEST_ASSERT_EQUAL_UINT(0, bus.read_count);
}

void test_S5_H10_oversized_line_is_discarded_and_its_tail_is_never_executed(void)
{
    /* A configure line fits; one byte more than the limit does not. */
    TEST_ASSERT_TRUE(sizeof(CONFIGURE) - 1 <= LC_CONSOLE_LINE_MAX);

    static char oversized[LC_CONSOLE_LINE_MAX + 2];
    memset(oversized, 'a', LC_CONSOLE_LINE_MAX + 1);
    oversized[LC_CONSOLE_LINE_MAX + 1] = '\0';
    feed_text(oversized);
    /* The rest of the same line is a valid command. */
    feed_text(" reset-network\n");
    TEST_ASSERT_EQUAL_UINT(0, ready_count);
    TEST_ASSERT_EQUAL_UINT(1, rejected_count);

    /* The same through the console: one error, nothing executed. */
    static uint8_t bytes[LC_CONSOLE_LINE_MAX + 64];
    memset(bytes, 'a', LC_CONSOLE_LINE_MAX + 1);
    size_t length = LC_CONSOLE_LINE_MAX + 1;
    memcpy(bytes + length, " reset-network\n", 15);
    length += 15;
    memset(&input, 0, sizeof(input));
    for (size_t offset = 0; offset < length;) {
        size_t taken = lc_console_feed_bytes(&console, &input, bytes + offset, length - offset);
        TEST_ASSERT_TRUE(taken > 0);
        offset += taken;
    }
    assert_nothing_happened();
    TEST_ASSERT_EQUAL_UINT(1, reply_count);
    TEST_ASSERT_EQUAL_STRING_LEN("error", replies, 5);

    /* The line after it is a line of its own. */
    const uint8_t next[] = "reset-network\n";
    TEST_ASSERT_EQUAL_UINT(sizeof(next) - 1,
                           lc_console_feed_bytes(&console, &input, next, sizeof(next) - 1));
    TEST_ASSERT_EQUAL_UINT(1, reset_count);
}

void test_S5_H11_line_with_nul_or_invalid_utf8_is_rejected(void)
{
    static const char WITH_NUL[] = "sta\0tus\n";
    feed(WITH_NUL, sizeof(WITH_NUL) - 1);
    TEST_ASSERT_EQUAL_UINT(0, ready_count);
    TEST_ASSERT_EQUAL_UINT(1, rejected_count);

    static const char *const INVALID_UTF8[] = {
        "stat\xFFus\n",       /* A byte that never occurs in UTF-8. */
        "stat\x80us\n",       /* A continuation byte with nothing before it. */
        "stat\xC3\n",         /* A sequence cut short by the newline. */
        "stat\xC0\xAFus\n",   /* An overlong encoding. */
        "stat\xED\xA0\x80\n", /* A surrogate. */
    };
    for (size_t i = 0; i < sizeof(INVALID_UTF8) / sizeof(INVALID_UTF8[0]); ++i) {
        feed_text(INVALID_UTF8[i]);
        TEST_ASSERT_EQUAL_UINT_MESSAGE(0, ready_count, INVALID_UTF8[i]);
        TEST_ASSERT_EQUAL_UINT(i + 2, rejected_count);
    }

    /* Valid UTF-8 beyond ASCII is accepted, as in a network name. */
    feed_text("caf\xC3\xA9\n");
    TEST_ASSERT_EQUAL_UINT(1, ready_count);
    TEST_ASSERT_EQUAL_STRING("caf\xC3\xA9", input.line);
}

void test_S5_H12_partial_line_idle_past_the_timeout_is_discarded(void)
{
    feed_text("reset-");
    lc_console_line_idle(&input, LC_CONSOLE_IDLE_TIMEOUT_MS - 1);
    feed_text("network\n");
    TEST_ASSERT_EQUAL_UINT(1, ready_count);
    TEST_ASSERT_EQUAL_STRING("reset-network", input.line);

    setUp();
    feed_text("reset-");
    lc_console_line_idle(&input, LC_CONSOLE_IDLE_TIMEOUT_MS);
    /* What follows is the tail of an abandoned line, not a command. */
    feed_text("network\n");
    TEST_ASSERT_EQUAL_UINT(0, ready_count);
    TEST_ASSERT_EQUAL_UINT(1, rejected_count);
    feed_text("status\n");
    TEST_ASSERT_EQUAL_UINT(1, ready_count);
    TEST_ASSERT_EQUAL_STRING("status", input.line);

    /* Idle time with no partial line is nothing to act on. */
    setUp();
    lc_console_line_idle(&input, 10 * LC_CONSOLE_IDLE_TIMEOUT_MS);
    feed_text("status\n");
    TEST_ASSERT_EQUAL_UINT(1, ready_count);
    TEST_ASSERT_EQUAL_UINT(0, rejected_count);
}

void test_S5_H13_unknown_command_gets_an_error_reply_and_changes_nothing(void)
{
    static const char *const UNKNOWN[] = {
        "bogus",
        "configure",
        "configure ",
        "configure not-json",
        "configure []",
        "configure {\"ssid\":7}",
        "reset-network now",
        "RESET-NETWORK",
        "",
    };

    for (size_t i = 0; i < sizeof(UNKNOWN) / sizeof(UNKNOWN[0]); ++i) {
        replies[0] = '\0';
        reply_count = 0;
        lc_console_execute(&console, UNKNOWN[i]);
        TEST_ASSERT_EQUAL_UINT_MESSAGE(1, reply_count, UNKNOWN[i]);
        TEST_ASSERT_EQUAL_STRING_LEN_MESSAGE("error", replies, 5, UNKNOWN[i]);
    }
    assert_nothing_happened();
}

void test_S5_H14_no_reply_contains_a_submitted_value(void)
{
    static const char *const SECRETS[] = {
        SSID, WIFI_PASSWORD, BROKER, BOOTSTRAP_USER, BOOTSTRAP_PASSWORD, TOKEN, "S3CRET", "s3cret",
    };

    lc_console_execute(&console, CONFIGURE);
    TEST_ASSERT_EQUAL_UINT(1, setup_count);
    TEST_ASSERT_EQUAL_STRING(SSID, submitted.ssid);
    TEST_ASSERT_EQUAL_STRING(WIFI_PASSWORD, submitted.wifi_password);
    TEST_ASSERT_EQUAL_STRING(BROKER, submitted.broker_url);
    TEST_ASSERT_EQUAL_STRING(BOOTSTRAP_USER, submitted.bootstrap_user);
    TEST_ASSERT_EQUAL_STRING(BOOTSTRAP_PASSWORD, submitted.bootstrap_password);
    TEST_ASSERT_EQUAL_STRING(TOKEN, submitted.token);

    /* A line that is refused, and one that is not even a command. */
    lc_console_execute(&console, "configure {\"ssid\":\"" SSID "\",\"token\":7}");
    lc_console_execute(&console, TOKEN);
    lc_console_execute(&console, "status");
    lc_console_execute(&console, "diagnostics");

    TEST_ASSERT_TRUE(reply_count >= 4);
    for (size_t i = 0; i < sizeof(SECRETS) / sizeof(SECRETS[0]); ++i) {
        TEST_ASSERT_NULL_MESSAGE(strstr(replies, SECRETS[i]), SECRETS[i]);
    }
    TEST_ASSERT_EQUAL_UINT(1, setup_count);
}

void test_S5_H15_input_processed_per_pass_is_bounded(void)
{
    static uint8_t flood[8 * LC_CONSOLE_BYTES_PER_PASS];
    for (size_t i = 0; i < sizeof(flood); ++i) {
        flood[i] = (uint8_t)(i % 7 == 6 ? '\n' : 'x');
    }

    size_t taken = lc_console_feed_bytes(&console, &input, flood, sizeof(flood));
    TEST_ASSERT_TRUE(taken > 0);
    TEST_ASSERT_TRUE(taken <= LC_CONSOLE_BYTES_PER_PASS);
    /* Every complete line in what was taken got its reply, and no more. */
    TEST_ASSERT_TRUE(reply_count <= LC_CONSOLE_BYTES_PER_PASS / 7 + 1);
    TEST_ASSERT_TRUE(reply_count >= 1);

    /* Called again, it carries on where it stopped. */
    size_t total = taken;
    unsigned passes = 1;
    while (total < sizeof(flood)) {
        replies[0] = '\0';
        taken = lc_console_feed_bytes(&console, &input, flood + total, sizeof(flood) - total);
        TEST_ASSERT_TRUE(taken > 0);
        TEST_ASSERT_TRUE(taken <= LC_CONSOLE_BYTES_PER_PASS);
        total += taken;
        ++passes;
    }
    TEST_ASSERT_TRUE(passes >= 8);
    assert_nothing_happened();

    TEST_ASSERT_EQUAL_UINT(0, lc_console_feed_bytes(&console, &input, flood, 0));
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S5_H10_oversized_line_is_discarded_and_its_tail_is_never_executed);
    RUN_TEST(test_S5_H11_line_with_nul_or_invalid_utf8_is_rejected);
    RUN_TEST(test_S5_H12_partial_line_idle_past_the_timeout_is_discarded);
    RUN_TEST(test_S5_H13_unknown_command_gets_an_error_reply_and_changes_nothing);
    RUN_TEST(test_S5_H14_no_reply_contains_a_submitted_value);
    RUN_TEST(test_S5_H15_input_processed_per_pass_is_bounded);
    return UNITY_END();
}
