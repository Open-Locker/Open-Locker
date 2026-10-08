#pragma once
#include "esp_err.h"
#include "locker_core.h"

typedef struct {
    uint32_t version;
    char client_id[40];
    char ap_password[33], setup_key[33]; /* Legacy v1 reserved fields; unused by USB setup. */
    char ssid[33], wifi_password[65], broker[193];
    char bootstrap_user[129], bootstrap_password[129], token[129];
    char username[129], password[129], locker_uuid[129];
    bool configured, enrolled, enrollment_pending;
} locker_settings_t;
esp_err_t locker_storage_init(locker_settings_t *settings, lc_config_t *config, lc_journal_t *journal);
esp_err_t locker_storage_settings(const locker_settings_t *settings);
esp_err_t locker_storage_config(const lc_config_t *config);
/* Deliberately keeps the safety journal; invalidates enrollment and mapping. */
esp_err_t locker_storage_reset_network(locker_settings_t *settings);
