#pragma once
#include "locker_core.h"
#include "esp_err.h"
esp_err_t lock_bus_start(void);
lc_unlock_t lock_bus_unlock(uint8_t board, unsigned zero_based_address);
bool lock_bus_read(uint8_t board, bool opening_feedback, lc_door_t states[255]);
bool lock_bus_connected(void);
uint32_t lock_bus_unlock_transmissions(void);
