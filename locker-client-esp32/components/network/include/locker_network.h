#pragma once
#include "locker_storage.h"
#include "esp_netif.h"
esp_err_t locker_network_init(const locker_settings_t *settings);
bool locker_network_ready(void);
