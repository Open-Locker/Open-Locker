#include "freertos/FreeRTOS.h"
#include "freertos/semphr.h"
#include "runtime.h"

static lc_locker_bus_port_t inner;
static SemaphoreHandle_t lock;

static bool apply_profile(void *ctx, const lc_hardware_profile_t *profile)
{
    (void)ctx;
    xSemaphoreTake(lock, portMAX_DELAY);
    bool applied = inner.apply_profile(inner.ctx, profile);
    xSemaphoreGive(lock);
    return applied;
}

static lc_unlock_observation_t unlock_compartment(void *ctx, lc_target_t target)
{
    (void)ctx;
    xSemaphoreTake(lock, portMAX_DELAY);
    lc_unlock_observation_t observation = inner.unlock_compartment(inner.ctx, target);
    xSemaphoreGive(lock);
    return observation;
}

static bool read_compartment_states(void *ctx, uint8_t board, const uint8_t *addresses,
                                    size_t count, lc_door_t *out)
{
    (void)ctx;
    xSemaphoreTake(lock, portMAX_DELAY);
    bool read = inner.read_compartment_states(inner.ctx, board, addresses, count, out);
    xSemaphoreGive(lock);
    return read;
}

static lc_bus_state_t connection_state(void *ctx)
{
    (void)ctx;
    /* A plain read of one field; it must not wait behind a request in progress. */
    return inner.connection_state(inner.ctx);
}

lc_locker_bus_port_t bus_guard(const lc_locker_bus_port_t *bus)
{
    inner = *bus;
    if (lock == NULL) {
        lock = xSemaphoreCreateMutex();
    }
    return (lc_locker_bus_port_t){
        .ctx = NULL,
        .apply_profile = apply_profile,
        .unlock_compartment = unlock_compartment,
        .read_compartment_states = read_compartment_states,
        .connection_state = connection_state,
    };
}
