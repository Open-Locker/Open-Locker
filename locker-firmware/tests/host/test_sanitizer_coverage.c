#include "fakes/probe.h"
#include "unity.h"

void setUp(void)
{
}

void tearDown(void)
{
}

#ifdef LC_SANITIZE_EXPECTED
void test_the_firmware_under_test_is_built_with_the_sanitizers(void)
{
    TEST_ASSERT_TRUE(lc_probe_under_test());
}

void test_the_fakes_and_the_fixture_are_built_with_the_sanitizers(void)
{
    TEST_ASSERT_TRUE(lc_probe_fakes());
    TEST_ASSERT_TRUE(lc_probe_fixture());
}
#else
void test_without_sanitizers_there_is_nothing_to_check(void)
{
    TEST_ASSERT_FALSE(lc_probe_under_test());
}
#endif

int main(void)
{
    UNITY_BEGIN();
#ifdef LC_SANITIZE_EXPECTED
    RUN_TEST(test_the_firmware_under_test_is_built_with_the_sanitizers);
    RUN_TEST(test_the_fakes_and_the_fixture_are_built_with_the_sanitizers);
#else
    RUN_TEST(test_without_sanitizers_there_is_nothing_to_check);
#endif
    return UNITY_END();
}
