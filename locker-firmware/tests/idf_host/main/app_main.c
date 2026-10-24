#include <stdlib.h>

#include "unity.h"

/* One Unity run for the suites of the adapters. */

void mqtt_transport_tests_reset(void);
void run_mqtt_transport_tests(void);
void ota_tests_reset(void);
void run_ota_tests(void);

/* The mocks of esp_https_ota and esp_ota_ops check the order of calls across both. */
int GlobalExpectCount;
int GlobalVerifyOrder;
char *GlobalOrderError;

void setUp(void)
{
    mqtt_transport_tests_reset();
    ota_tests_reset();
}

void tearDown(void)
{
}

void app_main(void)
{
    UNITY_BEGIN();
    run_mqtt_transport_tests();
    run_ota_tests();
    exit(UNITY_END());
}
