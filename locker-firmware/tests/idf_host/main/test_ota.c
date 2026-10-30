#include <stdarg.h>
#include <stdio.h>
#include <string.h>

#include "Mockesp_https_ota.h"
#include "Mockesp_ota_ops.h"
#include "esp_log.h"
#include "lc_ota_espidf/ota.h"
#include "unity.h"

/*
 * The OTA adapter against CMock mocks of the real esp_https_ota.h and esp_ota_ops.h.
 * Thin on purpose: the order of the calls that keep a bad image from being booted,
 * and that the download token never reaches a log line.
 */

#define TOKEN "tok-9f3a7c1e-d00d"
#define URL "https://open-locker.cloud/firmware/0.2.0/download?token=" TOKEN

static int fake_handle;
static esp_https_ota_handle_t const HANDLE = (esp_https_ota_handle_t)&fake_handle;
static const esp_partition_t RUNNING = {.subtype = ESP_PARTITION_SUBTYPE_APP_OTA_1};
static const esp_partition_t OTHER = {.subtype = ESP_PARTITION_SUBTYPE_APP_OTA_0};

static lc_firmware_update_port_t port;
static int verified_count;
static int failed_count;
static lc_error_t failed_error;
static const char *described_version;
static bool start_during_handler;
static char log_text[8192];
static vprintf_like_t previous_log;

static bool start_update(void);

/* While the download task reports, the adapter must still count as busy: the task still reads its state. */
static void note_busy(void)
{
    if (start_during_handler) {
        TEST_ASSERT_FALSE_MESSAGE(start_update(), "a second download could start while the first was reporting");
    }
}

static void on_verified(void *ctx)
{
    (void)ctx;
    note_busy();
    ++verified_count;
}

static void on_failed(void *ctx, lc_error_t error)
{
    (void)ctx;
    note_busy();
    ++failed_count;
    failed_error = error;
}

/* The host tests run the download where start() is called. */
bool lc_ota_spawn(void (*body)(void *arg), void *arg)
{
    body(arg);
    return true;
}

static int capture_log(const char *format, va_list arguments)
{
    size_t used = strlen(log_text);
    return vsnprintf(log_text + used, sizeof(log_text) - used, format, arguments);
}

static const lc_ota_config_t CONFIG = {.on_verified = on_verified, .on_failed = on_failed};

void ota_tests_reset(void)
{
    verified_count = 0;
    failed_count = 0;
    failed_error = LC_ERROR_NONE;
    described_version = "0.2.0";
    start_during_handler = false;
    log_text[0] = '\0';
    port = lc_ota_port(&CONFIG);
    Mockesp_https_ota_Init();
    Mockesp_ota_ops_Init();
    previous_log = esp_log_set_vprintf(capture_log);
}

static void finish_test(void)
{
    esp_log_set_vprintf(previous_log);
    Mockesp_https_ota_Verify();
    Mockesp_ota_ops_Verify();
    Mockesp_https_ota_Destroy();
    Mockesp_ota_ops_Destroy();
}

static void expect_begin(esp_err_t result)
{
    /* CMock keeps the pointer, not a copy: it has to outlive this function. */
    static esp_https_ota_handle_t handle;
    handle = HANDLE;
    esp_https_ota_begin_ExpectAnyArgsAndReturn(result);
    esp_https_ota_begin_ReturnThruPtr_handle(&handle);
}

static void expect_header(const char *version)
{
    static esp_app_desc_t header;
    memset(&header, 0, sizeof(header));
    snprintf(header.version, sizeof(header.version), "%s", version);
    esp_https_ota_get_img_desc_ExpectAnyArgsAndReturn(ESP_OK);
    esp_https_ota_get_img_desc_ReturnThruPtr_new_app_info(&header);
}

static bool start_update(void)
{
    lc_firmware_update_request_t request;
    memset(&request, 0, sizeof(request));
    snprintf(request.version, sizeof(request.version), "0.2.0");
    snprintf(request.url, sizeof(request.url), "%s", URL);
    return port.start(port.ctx, &request);
}

static void assert_log_has_no_token(void)
{
    TEST_ASSERT_TRUE(strlen(log_text) > 0);
    TEST_ASSERT_NULL(strstr(log_text, TOKEN));
    TEST_ASSERT_NULL(strstr(log_text, "token="));
}

static void test_the_header_version_is_checked_before_the_first_perform(void)
{
    expect_begin(ESP_OK);
    expect_header("0.9.9");
    /* No perform and no finish: a call to either fails the test. */
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(1, failed_count);
    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_VERIFY_FAILED, failed_error);
    TEST_ASSERT_EQUAL_INT(0, verified_count);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    assert_log_has_no_token();
    finish_test();
}

static void test_a_verified_image_is_staged_after_finish_and_no_slot_is_set_by_the_adapter(void)
{
    expect_begin(ESP_OK);
    expect_header("0.2.0");
    esp_https_ota_perform_ExpectAnyArgsAndReturn(ESP_ERR_HTTPS_OTA_IN_PROGRESS);
    esp_https_ota_perform_ExpectAnyArgsAndReturn(ESP_OK);
    esp_https_ota_is_complete_data_received_ExpectAnyArgsAndReturn(true);
    esp_https_ota_finish_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    TEST_ASSERT_TRUE(start_update());

    /* esp_https_ota_finish() selects the slot; esp_ota_set_boot_partition() is never called here. */
    TEST_ASSERT_EQUAL_INT(1, verified_count);
    TEST_ASSERT_EQUAL_INT(0, failed_count);
    TEST_ASSERT_TRUE(port.select_next_slot(port.ctx));
    assert_log_has_no_token();
    finish_test();
}

static void test_a_failed_transfer_aborts_and_selects_nothing(void)
{
    expect_begin(ESP_OK);
    expect_header("0.2.0");
    esp_https_ota_perform_ExpectAnyArgsAndReturn(ESP_FAIL);
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_DOWNLOAD_FAILED, failed_error);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    assert_log_has_no_token();
    finish_test();
}

static void test_an_image_that_fails_validation_aborts_as_a_failed_verification(void)
{
    expect_begin(ESP_OK);
    expect_header("0.2.0");
    esp_https_ota_perform_ExpectAnyArgsAndReturn(ESP_ERR_OTA_VALIDATE_FAILED);
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_VERIFY_FAILED, failed_error);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    finish_test();
}

static void test_a_download_that_ends_early_aborts(void)
{
    expect_begin(ESP_OK);
    expect_header("0.2.0");
    esp_https_ota_perform_ExpectAnyArgsAndReturn(ESP_OK);
    esp_https_ota_is_complete_data_received_ExpectAnyArgsAndReturn(false);
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_DOWNLOAD_FAILED, failed_error);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    finish_test();
}

static void test_a_failed_finish_is_a_failed_verification_and_selects_nothing(void)
{
    expect_begin(ESP_OK);
    expect_header("0.2.0");
    esp_https_ota_perform_ExpectAnyArgsAndReturn(ESP_OK);
    esp_https_ota_is_complete_data_received_ExpectAnyArgsAndReturn(true);
    /* finish() cleans up by itself on failure: no abort after it. */
    esp_https_ota_finish_ExpectAnyArgsAndReturn(ESP_ERR_OTA_VALIDATE_FAILED);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_VERIFY_FAILED, failed_error);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    finish_test();
}

static void test_a_connection_that_cannot_begin_is_a_failed_download(void)
{
    esp_https_ota_begin_ExpectAnyArgsAndReturn(ESP_FAIL);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_DOWNLOAD_FAILED, failed_error);
    assert_log_has_no_token();
    finish_test();
}

static esp_err_t abort_while_downloading(esp_https_ota_handle_t handle, int calls)
{
    (void)handle;
    (void)calls;
    port.abort(port.ctx);
    return ESP_ERR_HTTPS_OTA_IN_PROGRESS;
}

static void test_an_aborted_download_stops_and_reports_nothing(void)
{
    expect_begin(ESP_OK);
    expect_header("0.2.0");
    esp_https_ota_perform_Stub(abort_while_downloading);
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(0, verified_count);
    TEST_ASSERT_EQUAL_INT(0, failed_count);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    finish_test();
}

static esp_err_t describe_running(const esp_partition_t *partition, esp_app_desc_t *description,
                                  int calls)
{
    (void)partition;
    (void)calls;
    memset(description, 0, sizeof(*description));
    snprintf(description->version, sizeof(description->version), "%s", described_version);
    return ESP_OK;
}

static esp_err_t cannot_describe(const esp_partition_t *partition, esp_app_desc_t *description, int calls)
{
    (void)partition;
    (void)description;
    (void)calls;
    return ESP_ERR_NOT_FOUND;
}

static void test_the_state_of_the_running_image_comes_from_esp_ota_ops(void)
{
    esp_ota_img_states_t pending = ESP_OTA_IMG_PENDING_VERIFY;

    esp_ota_get_running_partition_ExpectAndReturn(&RUNNING);
    TEST_ASSERT_EQUAL_INT(LC_OTA_SLOT_1, port.running_slot(port.ctx));

    esp_ota_get_running_partition_ExpectAndReturn(&RUNNING);
    esp_ota_get_state_partition_ExpectAndReturn(&RUNNING, NULL, ESP_OK);
    esp_ota_get_state_partition_IgnoreArg_ota_state();
    esp_ota_get_state_partition_ReturnThruPtr_ota_state(&pending);
    TEST_ASSERT_TRUE(port.on_trial(port.ctx));

    esp_ota_get_running_partition_ExpectAndReturn(&RUNNING);
    esp_ota_get_partition_description_Stub(describe_running);
    TEST_ASSERT_EQUAL_STRING("0.2.0", port.running_version(port.ctx));
    finish_test();
}

static void test_a_rollback_that_returns_reports_that_it_did_not_happen(void)
{
    esp_ota_mark_app_invalid_rollback_and_reboot_ExpectAndReturn(ESP_FAIL);

    /* It returns: there was no image to go back to. Nothing is recorded: the bootloader keeps the state. */
    TEST_ASSERT_FALSE(port.rollback(port.ctx));
    finish_test();
}

static void test_a_valid_image_is_confirmed(void)
{
    esp_ota_mark_app_valid_cancel_rollback_ExpectAndReturn(ESP_OK);
    TEST_ASSERT_TRUE(port.mark_valid(port.ctx));

    esp_ota_mark_app_valid_cancel_rollback_ExpectAndReturn(ESP_FAIL);
    TEST_ASSERT_FALSE(port.mark_valid(port.ctx));
    finish_test();
}

/* The bootloader marks a slot invalid or aborted on any reset during a trial, not only on our rollback. */
static void test_the_last_rolled_back_version_is_the_version_of_the_invalid_slot(void)
{
    described_version = "0.1.9";
    esp_ota_get_last_invalid_partition_ExpectAndReturn(&OTHER);
    esp_ota_get_partition_description_Stub(describe_running);

    TEST_ASSERT_EQUAL_STRING("0.1.9", port.last_rollback_version(port.ctx));
    finish_test();
}

static void test_no_invalid_slot_means_no_rolled_back_version(void)
{
    esp_ota_get_last_invalid_partition_ExpectAndReturn(NULL);
    TEST_ASSERT_EQUAL_STRING("", port.last_rollback_version(port.ctx));
    finish_test();
}

static void test_an_invalid_slot_that_cannot_be_read_means_no_rolled_back_version(void)
{
    esp_ota_get_last_invalid_partition_ExpectAndReturn(&OTHER);
    esp_ota_get_partition_description_Stub(cannot_describe);

    TEST_ASSERT_EQUAL_STRING("", port.last_rollback_version(port.ctx));
    finish_test();
}

static void test_a_header_that_cannot_be_read_is_a_failed_download(void)
{
    expect_begin(ESP_OK);
    esp_https_ota_get_img_desc_ExpectAnyArgsAndReturn(ESP_FAIL);
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());

    TEST_ASSERT_EQUAL_INT(LC_ERROR_UPDATE_DOWNLOAD_FAILED, failed_error);
    TEST_ASSERT_FALSE(port.select_next_slot(port.ctx));
    finish_test();
}

static void test_the_adapter_is_busy_until_the_download_task_has_reported(void)
{
    start_during_handler = true;
    expect_begin(ESP_OK);
    expect_header("0.9.9");
    esp_https_ota_abort_ExpectAnyArgsAndReturn(ESP_OK);

    TEST_ASSERT_TRUE(start_update());
    TEST_ASSERT_EQUAL_INT(1, failed_count);

    /* Reported: free again. */
    start_during_handler = false;
    esp_https_ota_begin_ExpectAnyArgsAndReturn(ESP_FAIL);
    TEST_ASSERT_TRUE(start_update());
    finish_test();
}

void run_ota_tests(void)
{
    RUN_TEST(test_the_header_version_is_checked_before_the_first_perform);
    RUN_TEST(test_a_verified_image_is_staged_after_finish_and_no_slot_is_set_by_the_adapter);
    RUN_TEST(test_a_failed_transfer_aborts_and_selects_nothing);
    RUN_TEST(test_an_image_that_fails_validation_aborts_as_a_failed_verification);
    RUN_TEST(test_a_download_that_ends_early_aborts);
    RUN_TEST(test_a_failed_finish_is_a_failed_verification_and_selects_nothing);
    RUN_TEST(test_a_connection_that_cannot_begin_is_a_failed_download);
    RUN_TEST(test_an_aborted_download_stops_and_reports_nothing);
    RUN_TEST(test_the_state_of_the_running_image_comes_from_esp_ota_ops);
    RUN_TEST(test_a_rollback_that_returns_reports_that_it_did_not_happen);
    RUN_TEST(test_a_valid_image_is_confirmed);
    RUN_TEST(test_the_last_rolled_back_version_is_the_version_of_the_invalid_slot);
    RUN_TEST(test_no_invalid_slot_means_no_rolled_back_version);
    RUN_TEST(test_an_invalid_slot_that_cannot_be_read_means_no_rolled_back_version);
    RUN_TEST(test_a_header_that_cannot_be_read_is_a_failed_download);
    RUN_TEST(test_the_adapter_is_busy_until_the_download_task_has_reported);
}
