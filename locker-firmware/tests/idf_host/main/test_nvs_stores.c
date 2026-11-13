#include <stdint.h>
#include <stdio.h>
#include <string.h>

#include "lc_storage_nvs/storage.h"
#include "nvs.h"
#include "nvs_flash.h"
#include "unity.h"

/*
 * The NVS adapter over ESP-IDF's real NVS, on the Linux target's emulated flash
 * (partitions.csv). The host suites fake this port; here the real adapter must
 * keep the same promise: only a missing record is absent, anything it cannot
 * read is a failed load, so the application never writes over it.
 */

/* As nvs_stores.c writes them: a version word, then the structure. */
#define NAMESPACE "locker"
#define KEY_IDENTITY "identity"
#define RECORD_VERSION 1u

static void fresh(void)
{
    /* Erasing an initialised partition deinitialises it first. */
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_flash_erase());
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_flash_erase_partition("lc_setup"));
    TEST_ASSERT_TRUE(lc_storage_init());
}

static lc_identity_t enrolled_identity(void)
{
    lc_identity_t identity;
    memset(&identity, 0, sizeof(identity));
    snprintf(identity.client_id, sizeof(identity.client_id), "client-abc");
    snprintf(identity.mqtt_user, sizeof(identity.mqtt_user), "issued-user");
    identity.enrolled = true;
    return identity;
}

/* Writes a raw identity blob, as a record from another firmware version or a torn write would be. */
static void write_identity_blob(const void *bytes, size_t length)
{
    nvs_handle_t handle;
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_open(NAMESPACE, NVS_READWRITE, &handle));
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_set_blob(handle, KEY_IDENTITY, bytes, length));
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_commit(handle));
    nvs_close(handle);
}

static lc_load_t load_identity(lc_identity_t *out)
{
    const lc_identity_store_port_t store = lc_storage_identity_store();
    return store.load(store.ctx, out);
}

static void write_setup_secrets(const char *ssid, const char *passphrase, const char *password)
{
    nvs_handle_t handle;
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_open_from_partition("lc_setup", "lc_setup", NVS_READWRITE, &handle));
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_set_str(handle, "ssid", ssid));
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_set_str(handle, "ap_pass", passphrase));
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_set_str(handle, "setup_pw", password));
    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_commit(handle));
    nvs_close(handle);
}

static void test_an_empty_nvs_holds_no_identity(void)
{
    lc_identity_t identity;
    fresh();

    TEST_ASSERT_EQUAL_INT(LC_LOAD_ABSENT, load_identity(&identity));
}

static void test_a_saved_identity_loads_as_it_was_saved(void)
{
    const lc_identity_store_port_t store = lc_storage_identity_store();
    const lc_identity_t saved = enrolled_identity();
    lc_identity_t loaded;
    fresh();

    TEST_ASSERT_TRUE(store.save(store.ctx, &saved));

    TEST_ASSERT_EQUAL_INT(LC_LOAD_FOUND, load_identity(&loaded));
    TEST_ASSERT_EQUAL_MEMORY(&saved, &loaded, sizeof(saved));
}

/* A record of another size is there: overwriting it as absent would lose an enrollment. */
static void test_an_identity_of_another_size_is_a_failed_load_not_absent(void)
{
    uint8_t shorter[sizeof(uint32_t) + 16];
    lc_identity_t identity;
    fresh();
    memset(shorter, 0, sizeof(shorter));
    const uint32_t version = RECORD_VERSION;
    memcpy(shorter, &version, sizeof(version));
    write_identity_blob(shorter, sizeof(shorter));

    TEST_ASSERT_EQUAL_INT(LC_LOAD_FAILED, load_identity(&identity));
}

static void test_an_identity_of_another_version_is_a_failed_load_not_absent(void)
{
    uint8_t record[sizeof(uint32_t) + sizeof(lc_identity_t)];
    const lc_identity_t saved = enrolled_identity();
    lc_identity_t identity;
    fresh();
    const uint32_t version = RECORD_VERSION + 1;
    memcpy(record, &version, sizeof(version));
    memcpy(record + sizeof(version), &saved, sizeof(saved));
    write_identity_blob(record, sizeof(record));

    TEST_ASSERT_EQUAL_INT(LC_LOAD_FAILED, load_identity(&identity));
}

/* Clearing what is not there succeeds: a wipe that is retried after a restart must not fail. */
static void test_clearing_a_record_that_is_not_there_succeeds(void)
{
    const lc_settings_store_port_t settings = lc_storage_settings_store();
    const lc_config_store_port_t config = lc_storage_config_store();
    fresh();

    TEST_ASSERT_TRUE(settings.clear(settings.ctx));
    TEST_ASSERT_TRUE(config.clear(config.ctx));
}

static void test_setup_secrets_load_only_when_all_three_are_there_and_long_enough(void)
{
    lc_setup_secrets_t secrets;
    fresh();
    TEST_ASSERT_FALSE(lc_storage_load_setup_secrets(&secrets));

    write_setup_secrets("OpenLocker-1A2B", "short", "setup-password");
    TEST_ASSERT_FALSE(lc_storage_load_setup_secrets(&secrets));
    TEST_ASSERT_EQUAL_STRING("", secrets.passphrase);

    write_setup_secrets("OpenLocker-1A2B", "ap-passphrase", "setup-password");
    TEST_ASSERT_TRUE(lc_storage_load_setup_secrets(&secrets));
    TEST_ASSERT_EQUAL_STRING("OpenLocker-1A2B", secrets.ssid);
    TEST_ASSERT_EQUAL_STRING("ap-passphrase", secrets.passphrase);
    TEST_ASSERT_EQUAL_STRING("setup-password", secrets.setup_password);
}

/* Erasing nvs, as an unreadable nvs partition is on start, leaves the setup secrets alone. */
static void test_erasing_nvs_keeps_the_setup_secrets(void)
{
    lc_setup_secrets_t secrets;
    fresh();
    write_setup_secrets("OpenLocker-1A2B", "ap-passphrase", "setup-password");

    TEST_ASSERT_EQUAL_INT(ESP_OK, nvs_flash_erase());
    TEST_ASSERT_TRUE(lc_storage_init());

    TEST_ASSERT_TRUE(lc_storage_load_setup_secrets(&secrets));
}

void nvs_store_tests(void)
{
    RUN_TEST(test_an_empty_nvs_holds_no_identity);
    RUN_TEST(test_a_saved_identity_loads_as_it_was_saved);
    RUN_TEST(test_an_identity_of_another_size_is_a_failed_load_not_absent);
    RUN_TEST(test_an_identity_of_another_version_is_a_failed_load_not_absent);
    RUN_TEST(test_clearing_a_record_that_is_not_there_succeeds);
    RUN_TEST(test_setup_secrets_load_only_when_all_three_are_there_and_long_enough);
    RUN_TEST(test_erasing_nvs_keeps_the_setup_secrets);
}
