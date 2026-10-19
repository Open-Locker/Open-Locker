#include <stdbool.h>
#include <stdint.h>

#include "lc_button/button.h"
#include "unity.h"

#define SAMPLE_MS 20

static lc_button_t button;
static int64_t now;
static bool accept_posts;
static unsigned posts;
static unsigned abandoned;

void setUp(void)
{
    lc_button_init(&button);
    now = 100000;
    accept_posts = true;
    posts = 0;
    abandoned = 0;
}

void tearDown(void)
{
}

static void tick(bool level_high)
{
    lc_button_action_t action = lc_button_sample(&button, now, level_high);
    if (action == LC_BUTTON_POST_RESET) {
        ++posts;
        lc_button_post_result(&button, accept_posts);
    } else if (action == LC_BUTTON_RESET_ABANDONED) {
        ++abandoned;
    }
    now += SAMPLE_MS;
}

static void hold(bool level_high, int64_t ms)
{
    for (int64_t elapsed = 0; elapsed < ms; elapsed += SAMPLE_MS) {
        tick(level_high);
    }
}

/* The pin has been released for long enough to arm. */
static void arm(void)
{
    hold(true, 1500);
}

void test_S6_H16_a_press_under_10_seconds_does_nothing(void)
{
    arm();
    hold(false, 9980);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    hold(false, 100);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);
}

void test_S6_H17_a_press_from_10_to_under_30_seconds_resets_once(void)
{
    arm();
    hold(false, 10000);
    TEST_ASSERT_EQUAL_UINT(0, posts); /* Not before the release. */
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(1, posts);
    TEST_ASSERT_EQUAL_UINT(0, abandoned);

    setUp();
    arm();
    hold(false, 29980);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(1, posts);

    /* The reset is not repeated while the button stays released. */
    hold(true, 10000);
    TEST_ASSERT_EQUAL_UINT(1, posts);
}

void test_S6_H18_a_press_of_30_seconds_or_more_is_void(void)
{
    arm();
    hold(false, 30000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    setUp();
    arm();
    hold(false, 90000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);
}

void test_S6_H19_a_button_held_at_power_up_does_nothing(void)
{
    hold(false, 15000);
    hold(true, 500);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    /* It must have been released for a second before the next press counts. */
    hold(false, 10000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    arm();
    hold(false, 10000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(1, posts);
}

void test_S6_H20_a_stuck_pin_never_arms(void)
{
    hold(false, 120000);
    TEST_ASSERT_EQUAL_UINT(0, posts);
    TEST_ASSERT_EQUAL_UINT(0, abandoned);
}

void test_S6_H21_a_gap_between_samples_voids_the_press(void)
{
    arm();
    hold(false, 5000);
    now += 200; /* The sampling task did not run. */
    hold(false, 6000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    /* After a gap nothing is armed: a press straight away does not count either. */
    setUp();
    arm();
    now += 200;
    hold(false, 10000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    /* A gap of exactly the limit is still a continuous press. */
    setUp();
    arm();
    hold(false, 5000);
    now += LC_BUTTON_MAX_GAP_MS - SAMPLE_MS;
    hold(false, 5000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(1, posts);
}

void test_S6_H22_a_floating_pin_reads_released(void)
{
    /* Nothing pulls the pin down: the pull-up holds it high. */
    hold(true, 120000);
    TEST_ASSERT_EQUAL_UINT(0, posts);
    TEST_ASSERT_EQUAL_UINT(0, abandoned);
}

void test_S6_H23_bounce_under_50_ms_is_ignored(void)
{
    /* Short low pulses on a released pin never start a press. */
    arm();
    for (int pulse = 0; pulse < 600; ++pulse) {
        hold(false, 40);
        hold(true, 160);
    }
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(0, posts);

    /* A glitch inside a hold does not cut it short. */
    setUp();
    arm();
    hold(false, 5000);
    hold(true, 40);
    hold(false, 5000);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(1, posts);

    /* A glitch on release does not end it early either: two edges, one reset. */
    setUp();
    arm();
    hold(false, 10000);
    hold(true, 40);
    hold(false, 40);
    hold(true, 3000);
    TEST_ASSERT_EQUAL_UINT(1, posts);
}

void test_S6_H25_a_refused_reset_is_offered_again_for_2_seconds_then_reported(void)
{
    accept_posts = false;
    arm();
    hold(false, 10000);
    hold(true, 5000);

    TEST_ASSERT_TRUE(posts > 10);
    TEST_ASSERT_TRUE(posts <= LC_BUTTON_RETRY_MS / SAMPLE_MS);
    TEST_ASSERT_EQUAL_UINT(1, abandoned);
    unsigned total = posts;
    hold(true, 5000);
    TEST_ASSERT_EQUAL_UINT(total, posts);
    TEST_ASSERT_EQUAL_UINT(1, abandoned);

    /* Accepted on a later try: the retries stop, nothing is reported. */
    setUp();
    arm();
    hold(false, 10000);
    accept_posts = false;
    hold(true, 100); /* The release is recognised and the first try is refused. */
    accept_posts = true;
    hold(true, 5000);
    TEST_ASSERT_TRUE(posts >= 1);
    TEST_ASSERT_EQUAL_UINT(0, abandoned);
    unsigned accepted_at = posts;
    hold(true, 5000);
    TEST_ASSERT_EQUAL_UINT(accepted_at, posts);
}

int main(void)
{
    UNITY_BEGIN();
    RUN_TEST(test_S6_H16_a_press_under_10_seconds_does_nothing);
    RUN_TEST(test_S6_H17_a_press_from_10_to_under_30_seconds_resets_once);
    RUN_TEST(test_S6_H18_a_press_of_30_seconds_or_more_is_void);
    RUN_TEST(test_S6_H19_a_button_held_at_power_up_does_nothing);
    RUN_TEST(test_S6_H20_a_stuck_pin_never_arms);
    RUN_TEST(test_S6_H21_a_gap_between_samples_voids_the_press);
    RUN_TEST(test_S6_H22_a_floating_pin_reads_released);
    RUN_TEST(test_S6_H23_bounce_under_50_ms_is_ignored);
    RUN_TEST(test_S6_H25_a_refused_reset_is_offered_again_for_2_seconds_then_reported);
    return UNITY_END();
}
