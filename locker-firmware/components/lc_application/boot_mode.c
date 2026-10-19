#include "lc_application/boot_mode.h"

#include <string.h>

#include "lc_domain/identity.h"

/* The broker session identity (client_id) is made at every start and does not count. */
static bool identity_is_empty(const lc_identity_t *identity)
{
    return !identity->enrolled && !identity->enrollment_pending &&
           identity->bootstrap_user[0] == '\0' && identity->bootstrap_password[0] == '\0' &&
           identity->token[0] == '\0' && identity->mqtt_user[0] == '\0' &&
           identity->mqtt_password[0] == '\0' && identity->locker_uuid[0] == '\0';
}

lc_boot_result_t lc_boot_decide(const lc_boot_ports_t *ports)
{
    lc_boot_result_t result = {.mode = LC_BOOT_STATION};
    lc_identity_t identity;
    lc_network_settings_t settings;

    if (!ports->identity_store.load(ports->identity_store.ctx, &identity)) {
        memset(&identity, 0, sizeof(identity));
    }
    bool settings_present = ports->settings_store.load(ports->settings_store.ctx, &settings) &&
                            settings.configured;
    memset(&settings, 0, sizeof(settings));

    if (settings_present && identity_is_empty(&identity)) {
        result.repaired = true;
        /* Both are tried even if the first fails. */
        bool mapping_cleared = ports->config_store.clear(ports->config_store.ctx);
        bool settings_cleared = ports->settings_store.clear(ports->settings_store.ctx);
        result.repair_failed = !mapping_cleared || !settings_cleared;
        settings_present = !settings_cleared;
    }

    if (settings_present) {
        result.mode = LC_BOOT_STATION;
    } else {
        result.mode = identity.enrolled ? LC_BOOT_BENCH : LC_BOOT_SETUP;
    }
    return result;
}
