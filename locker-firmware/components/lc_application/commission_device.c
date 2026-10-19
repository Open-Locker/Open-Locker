#include "lc_application/commission_device.h"

#include <string.h>

#include "lc_domain/text.h"

void lc_commission_init(lc_commission_t *commission, const lc_commission_ports_t *ports)
{
    memset(commission, 0, sizeof(*commission));
    commission->ports = *ports;
}

void lc_commission_set_online(lc_commission_t *commission, bool online)
{
    commission->online = online;
}

void lc_commission_allow_plaintext_broker(lc_commission_t *commission, bool allow)
{
    commission->allow_plaintext_broker = allow;
}

/* A text field that fits its buffer, with room for the terminator. */
static bool terminated(const char *field, size_t capacity)
{
    return memchr(field, '\0', capacity) != NULL;
}

/* The URL starts with the scheme and names something after it. */
static bool has_scheme(const char *url, const char *scheme)
{
    size_t length = strlen(scheme);
    return strncmp(url, scheme, length) == 0 && url[length] != '\0';
}

static bool wifi_is_valid(const lc_setup_request_t *request)
{
    if (!terminated(request->ssid, sizeof(request->ssid)) ||
        !terminated(request->wifi_password, sizeof(request->wifi_password))) {
        return false;
    }
    size_t ssid = strlen(request->ssid);
    size_t password = strlen(request->wifi_password);
    /* An open network has no password; otherwise WPA2 bounds apply. */
    bool password_ok = password == 0 || (password >= LC_WIFI_PASSWORD_MIN &&
                                         password <= LC_WIFI_PASSWORD_MAX);
    return ssid >= 1 && ssid <= LC_SSID_MAX && password_ok;
}

static bool broker_is_valid(const lc_commission_t *commission, const lc_setup_request_t *request)
{
    /* Plain mqtt:// is for development builds only; mqtts:// is always verified. */
    return terminated(request->broker_url, sizeof(request->broker_url)) &&
           (has_scheme(request->broker_url, "mqtts://") ||
            (commission->allow_plaintext_broker && has_scheme(request->broker_url, "mqtt://")));
}

static bool enrollment_is_valid(const lc_setup_request_t *request)
{
    return terminated(request->bootstrap_user, sizeof(request->bootstrap_user)) &&
           terminated(request->bootstrap_password, sizeof(request->bootstrap_password)) &&
           terminated(request->token, sizeof(request->token)) &&
           request->bootstrap_user[0] != '\0' && request->bootstrap_password[0] != '\0' &&
           request->token[0] != '\0';
}

static lc_commission_result_t answer(lc_commission_t *commission, lc_commission_result_t result)
{
    commission->ports.reply(commission->ports.ctx, result);
    return result;
}

/* Tells the backend the device is going away, when there is a connection to tell it on. */
static void say_goodbye(lc_commission_t *commission)
{
    if (commission->online) {
        (void)commission->ports.outbound.connection_offline(commission->ports.outbound.ctx,
                                                            LC_OFFLINE_SHUTDOWN);
    }
}

lc_commission_result_t lc_commission_apply(lc_commission_t *commission,
                                           const lc_setup_request_t *request)
{
    const lc_commission_ports_t *ports = &commission->ports;
    lc_identity_t identity;

    if (!ports->identity_store.load(ports->identity_store.ctx, &identity)) {
        memset(&identity, 0, sizeof(identity));
    }

    /* The token has left the device and its fate is unknown: only a reset helps. */
    if (identity.enrollment_pending) {
        return answer(commission, LC_COMMISSION_RESET_REQUIRED);
    }

    /* Kept so a failed identity save can put the network back: old or new, never a mix. */
    lc_network_settings_t previous;
    bool had_previous = ports->settings_store.load(ports->settings_store.ctx, &previous);

    /*
     * An enrolled device takes the Wi-Fi only: the broker URL it enrolled with
     * stays, and broker, token and bootstrap fields in the request are ignored.
     */
    bool keep_broker = identity.enrolled && had_previous && previous.broker_url[0] != '\0';
    if (!wifi_is_valid(request) || (!keep_broker && !broker_is_valid(commission, request)) ||
        (!identity.enrolled && !enrollment_is_valid(request))) {
        return answer(commission, LC_COMMISSION_INVALID);
    }

    lc_network_settings_t settings;
    memset(&settings, 0, sizeof(settings));
    settings.configured = true;
    (void)lc_text_copy(settings.ssid, sizeof(settings.ssid), request->ssid);
    (void)lc_text_copy(settings.wifi_password, sizeof(settings.wifi_password),
                       request->wifi_password);
    (void)lc_text_copy(settings.broker_url, sizeof(settings.broker_url),
                       keep_broker ? previous.broker_url : request->broker_url);
    if (!ports->settings_store.save(ports->settings_store.ctx, &settings)) {
        return answer(commission, LC_COMMISSION_SAVE_FAILED);
    }

    if (!identity.enrolled) {
        (void)lc_text_copy(identity.bootstrap_user, sizeof(identity.bootstrap_user),
                           request->bootstrap_user);
        (void)lc_text_copy(identity.bootstrap_password, sizeof(identity.bootstrap_password),
                           request->bootstrap_password);
        (void)lc_text_copy(identity.token, sizeof(identity.token), request->token);
        if (!ports->identity_store.save(ports->identity_store.ctx, &identity)) {
            if (had_previous) {
                (void)ports->settings_store.save(ports->settings_store.ctx, &previous);
            } else {
                (void)ports->settings_store.clear(ports->settings_store.ctx);
            }
            return answer(commission, LC_COMMISSION_SAVE_FAILED);
        }
    }

    /* Only once everything is stored: a failed save must leave the device taking commands. */
    ports->stop_commands(ports->ctx);
    (void)answer(commission, LC_COMMISSION_SAVED);
    say_goodbye(commission);
    ports->system.restart(ports->system.ctx);
    return LC_COMMISSION_SAVED;
}

bool lc_commission_reset(lc_commission_t *commission)
{
    const lc_commission_ports_t *ports = &commission->ports;
    lc_identity_t cleared;

    /* The identity goes first: without it the rest is a device waiting to be set up. */
    memset(&cleared, 0, sizeof(cleared));
    if (!ports->identity_store.save(ports->identity_store.ctx, &cleared)) {
        return false;
    }
    ports->stop_commands(ports->ctx);
    (void)ports->config_store.clear(ports->config_store.ctx);
    (void)ports->settings_store.clear(ports->settings_store.ctx);

    say_goodbye(commission);
    ports->system.restart(ports->system.ctx);
    return true;
}
