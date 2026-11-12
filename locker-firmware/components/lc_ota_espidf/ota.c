#include "lc_ota_espidf/ota.h"

#include <string.h>

#include "esp_app_desc.h"
#include "esp_crt_bundle.h"
#include "esp_http_client.h"
#include "esp_https_ota.h"
#include "esp_log.h"
#include "esp_ota_ops.h"
#include "lc_domain/text.h"

static const char *TAG = "lc_ota";

/* A stalled transfer ends the download instead of holding the task for ever. */
#define HTTP_TIMEOUT_MS 15000

static struct {
    lc_ota_config_t config;
    lc_firmware_update_request_t request;
    char running_version[LC_FIRMWARE_VERSION_MAX + 1];
    char last_rollback_version[LC_FIRMWARE_VERSION_MAX + 1];
    /*
     * Written by the owner task and the download task. `busy` is the download task's lock on
     * `request`: start() overwrites it as soon as `busy` is clear.
     */
    volatile bool busy;
    volatile bool abort_requested;
    volatile bool staged;
} state;

/* ---- The download, on its own task ---- */

/*
 * Checks the header before the first byte is written, downloads, and lets
 * esp_https_ota_finish() verify the image and select its slot.
 */
static lc_error_t fetch(const esp_https_ota_config_t *config)
{
    esp_https_ota_handle_t handle = NULL;
    esp_app_desc_t header;

    if (esp_https_ota_begin(config, &handle) != ESP_OK) {
        return LC_ERROR_UPDATE_DOWNLOAD_FAILED;
    }
    if (esp_https_ota_get_img_desc(handle, &header) != ESP_OK) {
        /* The header did not arrive: the transfer, not the image, failed. */
        (void)esp_https_ota_abort(handle);
        return LC_ERROR_UPDATE_DOWNLOAD_FAILED;
    }
    if (strncmp(header.version, state.request.version, sizeof(header.version)) != 0) {
        (void)esp_https_ota_abort(handle);
        return LC_ERROR_UPDATE_VERIFY_FAILED;
    }

    esp_err_t result;
    do {
        result = esp_https_ota_perform(handle);
    } while (result == ESP_ERR_HTTPS_OTA_IN_PROGRESS && !state.abort_requested);

    if (result != ESP_OK || state.abort_requested || !esp_https_ota_is_complete_data_received(handle)) {
        (void)esp_https_ota_abort(handle);
        return result == ESP_ERR_OTA_VALIDATE_FAILED || result == ESP_ERR_INVALID_VERSION
                   ? LC_ERROR_UPDATE_VERIFY_FAILED
                   : LC_ERROR_UPDATE_DOWNLOAD_FAILED;
    }
    /* Past this point the new slot is selected: the application's abort cannot undo it. */
    if (esp_https_ota_finish(handle) != ESP_OK) {
        return LC_ERROR_UPDATE_VERIFY_FAILED;
    }
    state.staged = true;
    return LC_ERROR_NONE;
}

static void download(void *unused)
{
    (void)unused;
    const esp_http_client_config_t http = {
        .url = state.request.url,
        .crt_bundle_attach = esp_crt_bundle_attach,
        .timeout_ms = HTTP_TIMEOUT_MS,
    };
    const esp_https_ota_config_t config = {.http_config = &http};

    /* The HTTP client prints the whole URL when it cannot parse it, and with it the token. */
    const esp_log_level_t client_level = esp_log_level_get("HTTP_CLIENT");
    esp_log_level_set("HTTP_CLIENT", ESP_LOG_NONE);
    ESP_LOGI(TAG, "downloading firmware %s", state.request.version);
    const lc_error_t error = fetch(&config);
    esp_log_level_set("HTTP_CLIENT", client_level);

    const bool aborted = state.abort_requested;
    if (aborted) {
        /* The application asked to stop and wants no event. */
        ESP_LOGW(TAG, "firmware download aborted");
    } else if (error == LC_ERROR_NONE) {
        ESP_LOGI(TAG, "firmware %s verified", state.request.version);
        state.config.on_verified(state.config.ctx);
    } else {
        ESP_LOGW(TAG, "firmware download failed (%d)", (int)error);
        state.config.on_failed(state.config.ctx, error);
    }
    /*
     * Last: from here start() may overwrite the request, so nothing above may read it after this
     * line. The URL copy is wiped just before. A command that arrives while the handler runs is
     * refused as not ready; the backend sends it again.
     */
    memset(state.request.url, 0, sizeof(state.request.url));
    state.busy = false;
}

/* ---- The port ---- */

static const char *running_version(void *ctx)
{
    (void)ctx;
    esp_app_desc_t description;
    if (state.running_version[0] == '\0' &&
        esp_ota_get_partition_description(esp_ota_get_running_partition(), &description) == ESP_OK) {
        (void)lc_text_copy(state.running_version, sizeof(state.running_version), description.version);
    }
    return state.running_version;
}

static lc_ota_slot_t running_slot(void *ctx)
{
    (void)ctx;
    const esp_partition_t *running = esp_ota_get_running_partition();
    return running != NULL && running->subtype == ESP_PARTITION_SUBTYPE_APP_OTA_1 ? LC_OTA_SLOT_1
                                                                                  : LC_OTA_SLOT_0;
}

static bool on_trial(void *ctx)
{
    (void)ctx;
    esp_ota_img_states_t image;
    return esp_ota_get_state_partition(esp_ota_get_running_partition(), &image) == ESP_OK &&
           image == ESP_OTA_IMG_PENDING_VERIFY;
}

/* The bootloader marks the slot invalid or aborted; its version is the one to refuse. */
static const char *last_rollback_version(void *ctx)
{
    (void)ctx;
    esp_app_desc_t description;
    const esp_partition_t *invalid = esp_ota_get_last_invalid_partition();

    state.last_rollback_version[0] = '\0';
    if (invalid != NULL && esp_ota_get_partition_description(invalid, &description) == ESP_OK) {
        (void)lc_text_copy(state.last_rollback_version, sizeof(state.last_rollback_version),
                           description.version);
    }
    return state.last_rollback_version;
}

static bool start(void *ctx, const lc_firmware_update_request_t *request)
{
    (void)ctx;
    if (state.busy) {
        return false;
    }
    state.request = *request;
    state.abort_requested = false;
    state.staged = false;
    state.busy = true;
    if (!lc_ota_spawn(download, NULL)) {
        state.busy = false;
        return false;
    }
    return true;
}

static void abort_download(void *ctx)
{
    (void)ctx;
    state.abort_requested = true;
}

static bool select_next_slot(void *ctx)
{
    (void)ctx;
    return state.staged;
}

static bool mark_valid(void *ctx)
{
    (void)ctx;
    return esp_ota_mark_app_valid_cancel_rollback() == ESP_OK;
}

static bool rollback(void *ctx)
{
    (void)ctx;
    /* Returns only when it could not roll back. */
    (void)esp_ota_mark_app_invalid_rollback_and_reboot();
    return false;
}

lc_firmware_update_port_t lc_ota_port(const lc_ota_config_t *config)
{
    memset(&state, 0, sizeof(state));
    state.config = *config;
    return (lc_firmware_update_port_t){
        .ctx = NULL,
        .running_version = running_version,
        .running_slot = running_slot,
        .on_trial = on_trial,
        .last_rollback_version = last_rollback_version,
        .start = start,
        .abort = abort_download,
        .select_next_slot = select_next_slot,
        .mark_valid = mark_valid,
        .rollback = rollback,
    };
}
