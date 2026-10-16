#include <ctype.h>
#include <string.h>

#include "lc_application/app.h"
#include "lc_application/internal.h"
#include "lc_domain/text.h"

static bool same_hash(const char *a, const char *b)
{
    if (strlen(a) != strlen(b)) {
        return false;
    }
    for (; *a != '\0'; ++a, ++b) {
        if (tolower((unsigned char)*a) != tolower((unsigned char)*b)) {
            return false;
        }
    }
    return true;
}

/* Validates the request against its own hash. */
static lc_error_t build(lc_app_t *app, const lc_command_t *command, lc_config_t *next)
{
    char canonical[LC_CONFIG_CANONICAL_MAX];
    char computed[LC_SHA256_HEX_SIZE];

    if (!lc_config_from_request(&command->config, next) ||
        !lc_config_canonical(next, canonical, sizeof(canonical)) ||
        !app->ports.hasher.sha256_hex(app->ports.hasher.ctx, canonical, computed)) {
        return LC_ERROR_INVALID_CONFIG;
    }
    if (!same_hash(computed, next->hash)) {
        return LC_ERROR_INVALID_CONFIG;
    }
    /* The hash the backend compares against is the one computed here. */
    (void)lc_text_copy(next->hash, sizeof(next->hash), computed);
    return LC_ERROR_NONE;
}

lc_command_result_t lc_app_apply_config(lc_app_t *app, const lc_command_t *command)
{
    lc_command_result_t result;
    memset(&result, 0, sizeof(result));
    (void)lc_text_copy(result.transaction_id, sizeof(result.transaction_id),
                       command->transaction_id);
    (void)lc_text_copy(result.action_name, sizeof(result.action_name), command->action_name);

    lc_config_t next;
    result.error = build(app, command, &next);
    if (result.error != LC_ERROR_NONE) {
        return result;
    }

    const lc_locker_bus_port_t *bus = &app->ports.bus;
    const lc_config_store_port_t *store = &app->ports.config_store;

    if (!bus->apply_profile(bus->ctx, &next.profile)) {
        result.error = LC_ERROR_HARDWARE_ERROR;
        return result;
    }
    if (!store->save(store->ctx, &next)) {
        /* The previous configuration stays in effect, on the bus as well. */
        if (app->config.applied) {
            (void)bus->apply_profile(bus->ctx, &app->config.profile);
        }
        result.error = LC_ERROR_UNKNOWN_ERROR;
        return result;
    }

    app->config = next;
    lc_app_reset_doors(app);
    lc_app_restart_heartbeat(app, app->ports.clock.now_ms(app->ports.clock.ctx));
    (void)lc_text_copy(result.applied_config_hash, sizeof(result.applied_config_hash), next.hash);
    return result;
}
