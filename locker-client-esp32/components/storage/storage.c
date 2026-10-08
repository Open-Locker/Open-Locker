#include "locker_storage.h"
#include <stdio.h>
#include <string.h>
#include "esp_partition.h"
#include "esp_random.h"
#include "nvs_flash.h"
#include "nvs.h"
#include "bootloader_random.h"

static nvs_handle_t handle;
static bool read_flash(void *p, size_t offset, void *out, size_t n)
{
    return esp_partition_read(p, offset, out, n) == ESP_OK;
}
static bool write_flash(void *p, size_t offset, const void *in, size_t n)
{
    return esp_partition_write(p, offset, in, n) == ESP_OK;
}
static esp_err_t save(const char *key, const void *data, size_t n)
{
    esp_err_t err = nvs_set_blob(handle, key, data, n);
    return err == ESP_OK ? nvs_commit(handle) : err;
}
esp_err_t locker_storage_settings(const locker_settings_t *s) { return save("settings", s, sizeof(*s)); }
esp_err_t locker_storage_config(const lc_config_t *c) { return save("config", c, sizeof(*c)); }

static void random_hex(char *out, size_t bytes)
{
    for (size_t i = 0; i < bytes; ++i) sprintf(out + 2 * i, "%02x", (unsigned)(esp_random() & 0xff));
}

esp_err_t locker_storage_init(locker_settings_t *s, lc_config_t *c, lc_journal_t *j)
{
    /* Do not use the common erase-and-retry example: credential/config loss is
     * not a safe response to NVS corruption or a full partition. */
    esp_err_t err = nvs_flash_init();
    if (err != ESP_OK) return err;
    err = nvs_open("locker", NVS_READWRITE, &handle);
    if (err != ESP_OK) return err;
    size_t size = sizeof(*s);
    memset(s, 0, size);
    err = nvs_get_blob(handle, "settings", s, &size);
    if (err == ESP_ERR_NVS_NOT_FOUND) {
        s->version = 1;
        bootloader_random_enable(); /* true entropy before the Wi-Fi driver starts */
        char suffix[17];
        random_hex(suffix, 8);
        snprintf(s->client_id, sizeof(s->client_id), "locker-client-%s", suffix);
        bootloader_random_disable();
        strcpy(s->broker, "mqtts://open-locker.cloud:8883");
        err = locker_storage_settings(s);
    } else if (err == ESP_OK && (size != sizeof(*s) || s->version != 1)) {
        err = ESP_ERR_INVALID_STATE;
    }
    if (err != ESP_OK) return err;
    /* Each persisted char array must retain a terminator. */
#define CHECK_STRING(field) if (!memchr(s->field, 0, sizeof(s->field))) return ESP_ERR_INVALID_STATE
    CHECK_STRING(client_id); CHECK_STRING(ap_password); CHECK_STRING(setup_key);
    CHECK_STRING(ssid); CHECK_STRING(wifi_password); CHECK_STRING(broker);
    CHECK_STRING(bootstrap_user); CHECK_STRING(bootstrap_password); CHECK_STRING(token);
    CHECK_STRING(username); CHECK_STRING(password); CHECK_STRING(locker_uuid);
#undef CHECK_STRING
    if (!*s->client_id || 
        (s->configured && (!*s->ssid || !lc_wifi_password(s->wifi_password) || strncmp(s->broker, "mqtts://", 8))) ||
        (s->enrolled && (!*s->username || !*s->password || !*s->locker_uuid))) return ESP_ERR_INVALID_STATE;
    memset(c, 0, sizeof(*c));
    size = sizeof(*c);
    err = nvs_get_blob(handle, "config", c, &size);
    if (err != ESP_OK && err != ESP_ERR_NVS_NOT_FOUND) return err;
    if (err == ESP_OK && (size != sizeof(*c) || c->count > LC_COMPARTMENTS_MAX || !c->applied ||
                         c->heartbeat_seconds == 0 || c->hash[64] != 0)) return ESP_ERR_INVALID_STATE;
    const esp_partition_t *p = esp_partition_find_first(ESP_PARTITION_TYPE_DATA, 0x40, "journal");
    if (!p || !lc_journal_open(j, (lc_journal_io_t){(void *)p, p->size, read_flash, write_flash}))
        return ESP_ERR_INVALID_STATE;
    return ESP_OK;
}

esp_err_t locker_storage_reset_network(locker_settings_t *s)
{
    /* Never pair a new bank identity with a prior bank's physical mapping. */
    esp_err_t err = nvs_erase_key(handle, "config");
    if (err != ESP_OK && err != ESP_ERR_NVS_NOT_FOUND) return err;
    err = nvs_commit(handle);
    if (err != ESP_OK) return err;
    s->configured = s->enrolled = s->enrollment_pending = false;
    memset(s->ssid, 0, sizeof(s->ssid));
    memset(s->wifi_password, 0, sizeof(s->wifi_password));
    memset(s->bootstrap_user, 0, sizeof(s->bootstrap_user));
    memset(s->bootstrap_password, 0, sizeof(s->bootstrap_password));
    memset(s->token, 0, sizeof(s->token));
    memset(s->username, 0, sizeof(s->username));
    memset(s->password, 0, sizeof(s->password));
    memset(s->locker_uuid, 0, sizeof(s->locker_uuid));
    return locker_storage_settings(s);
}
