#include <string.h>

#include "esp_err.h"
#include "esp_log.h"
#include "lc_storage_nvs/storage.h"
#include "nvs.h"
#include "nvs_flash.h"

#define NAMESPACE "locker"
#define KEY_CONFIG "config"
#define KEY_IDENTITY "identity"
#define KEY_SETTINGS "settings"
/* Bump when a stored structure changes shape; an older record is then not loaded. */
#define RECORD_VERSION 1u

static const char *TAG = "lc_storage";

bool lc_storage_init(void)
{
    esp_err_t err = nvs_flash_init();
    if (err == ESP_ERR_NVS_NO_FREE_PAGES || err == ESP_ERR_NVS_NEW_VERSION_FOUND) {
        ESP_LOGW(TAG, "NVS cannot be used as it is and is erased");
        err = nvs_flash_erase();
        if (err == ESP_OK) {
            err = nvs_flash_init();
        }
    }
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "NVS init failed: %s", esp_err_to_name(err));
    }
    return err == ESP_OK;
}

/* Largest value stored here, with room for the version. */
#define BUFFER_MAX 1024

static bool load_record(const char *key, void *out, size_t size)
{
    nvs_handle_t handle;
    if (nvs_open(NAMESPACE, NVS_READONLY, &handle) != ESP_OK) {
        return false;
    }
    uint8_t buffer[BUFFER_MAX];
    size_t length = sizeof(buffer);
    bool found = size + sizeof(uint32_t) <= sizeof(buffer) &&
                 nvs_get_blob(handle, key, buffer, &length) == ESP_OK &&
                 length == size + sizeof(uint32_t);
    nvs_close(handle);

    uint32_t version;
    if (!found) {
        return false;
    }
    memcpy(&version, buffer, sizeof(version));
    if (version != RECORD_VERSION) {
        return false;
    }
    memcpy(out, buffer + sizeof(version), size);
    return true;
}

static bool save_record(const char *key, const void *value, size_t size)
{
    uint8_t buffer[BUFFER_MAX];
    uint32_t version = RECORD_VERSION;
    if (size + sizeof(version) > sizeof(buffer)) {
        return false;
    }
    memcpy(buffer, &version, sizeof(version));
    memcpy(buffer + sizeof(version), value, size);

    nvs_handle_t handle;
    if (nvs_open(NAMESPACE, NVS_READWRITE, &handle) != ESP_OK) {
        return false;
    }
    /* One key, one commit: after a power loss the old record or the new one is read. */
    bool saved = nvs_set_blob(handle, key, buffer, size + sizeof(version)) == ESP_OK &&
                 nvs_commit(handle) == ESP_OK;
    nvs_close(handle);
    if (!saved) {
        ESP_LOGE(TAG, "saving %s failed", key);
    }
    return saved;
}

static bool clear_record(const char *key)
{
    nvs_handle_t handle;
    if (nvs_open(NAMESPACE, NVS_READWRITE, &handle) != ESP_OK) {
        return false;
    }
    esp_err_t err = nvs_erase_key(handle, key);
    bool cleared = (err == ESP_OK || err == ESP_ERR_NVS_NOT_FOUND) && nvs_commit(handle) == ESP_OK;
    nvs_close(handle);
    return cleared;
}

/* ---- Mapping ---- */

static bool config_load(void *ctx, lc_config_t *out)
{
    (void)ctx;
    return load_record(KEY_CONFIG, out, sizeof(*out)) && out->applied;
}

static bool config_save(void *ctx, const lc_config_t *config)
{
    (void)ctx;
    return save_record(KEY_CONFIG, config, sizeof(*config));
}

static bool config_clear(void *ctx)
{
    (void)ctx;
    return clear_record(KEY_CONFIG);
}

lc_config_store_port_t lc_storage_config_store(void)
{
    return (lc_config_store_port_t){
        .ctx = NULL, .load = config_load, .save = config_save, .clear = config_clear};
}

/* ---- Identity ---- */

static bool identity_load(void *ctx, lc_identity_t *out)
{
    (void)ctx;
    return load_record(KEY_IDENTITY, out, sizeof(*out));
}

static bool identity_save(void *ctx, const lc_identity_t *identity)
{
    (void)ctx;
    return save_record(KEY_IDENTITY, identity, sizeof(*identity));
}

lc_identity_store_port_t lc_storage_identity_store(void)
{
    return (lc_identity_store_port_t){.ctx = NULL, .load = identity_load, .save = identity_save};
}

/* ---- Network settings ---- */

static bool settings_load(void *ctx, lc_network_settings_t *out)
{
    (void)ctx;
    return load_record(KEY_SETTINGS, out, sizeof(*out)) && out->configured;
}

static bool settings_save(void *ctx, const lc_network_settings_t *settings)
{
    (void)ctx;
    return save_record(KEY_SETTINGS, settings, sizeof(*settings));
}

static bool settings_clear(void *ctx)
{
    (void)ctx;
    return clear_record(KEY_SETTINGS);
}

lc_settings_store_port_t lc_storage_settings_store(void)
{
    return (lc_settings_store_port_t){
        .ctx = NULL, .load = settings_load, .save = settings_save, .clear = settings_clear};
}
