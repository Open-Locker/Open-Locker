#include <string.h>

#include "lc_application/app.h"
#include "lc_application/internal.h"
#include "lc_domain/door_detection.h"
#include "lc_domain/text.h"

lc_command_result_t lc_app_open_compartment(lc_app_t *app, const lc_command_t *command)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    (void)lc_text_copy(result.transaction_id, sizeof(result.transaction_id),
                       command->transaction_id);
    (void)lc_text_copy(result.action_name, sizeof(result.action_name), command->action_name);

    if (!app->config.applied) {
        result.error = LC_ERROR_RUNTIME_CONFIG_NOT_APPLIED;
        return result;
    }
    const lc_mapping_t *mapping = lc_config_find(&app->config, command->compartment_number);
    if (mapping == NULL) {
        result.error = LC_ERROR_COMPARTMENT_NOT_FOUND;
        return result;
    }

    const lc_target_t target = {
        .compartment_number = mapping->number,
        .board = mapping->board,
        .address = mapping->address,
    };
    /* Once. The bus adapter never retries and neither does anything above it. */
    lc_unlock_observation_t observation =
        app->ports.bus.unlock_compartment(app->ports.bus.ctx, target);

    if (observation.result == LC_UNLOCK_NOT_SENT) {
        /* Nothing left the device, so there is no door to watch. */
        result.error = LC_ERROR_HARDWARE_ERROR;
        return result;
    }

    const size_t index = (size_t)(mapping - app->config.mappings);
    const int64_t now_ms = app->ports.clock.now_ms(app->ports.clock.ctx);

    const bool opened_only = observation.result == LC_UNLOCK_OUTCOME_UNKNOWN;

    /* Acknowledged or not, the lock may have moved. */
    lc_door_unlocked(&app->compartments[index].door, mapping->number, command->transaction_id,
                     now_ms, opened_only);

    if (observation.result == LC_UNLOCK_OUTCOME_UNKNOWN) {
        result.error = LC_ERROR_HARDWARE_ERROR;
        return result;
    }

    /* The panel answered: success, and the reply decides the door when it carries one. */
    lc_door_finish_from_reply(&app->compartments[index].door, observation.observed, now_ms);
    result.error = LC_ERROR_NONE;
    return result;
}