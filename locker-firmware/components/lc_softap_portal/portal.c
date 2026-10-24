#include "lc_softap_portal/portal.h"

#include <string.h>

void lc_portal_init(lc_portal_t *portal)
{
    memset(portal, 0, sizeof(*portal));
    portal->state = LC_PORTAL_CLOSED_NOT_OPENED;
}

bool lc_portal_is_open(const lc_portal_t *portal)
{
    return portal->state == LC_PORTAL_OPEN;
}

static void close_with(lc_portal_t *portal, lc_portal_state_t reason)
{
    portal->state = reason;
    portal->station_present = false;
    portal->session_established = false;
}

void lc_portal_open(lc_portal_t *portal, int64_t now_ms)
{
    if (portal->state != LC_PORTAL_CLOSED_NOT_OPENED) {
        return;
    }
    portal->state = LC_PORTAL_OPEN;
    portal->opened_ms = now_ms;
}

bool lc_portal_tick(lc_portal_t *portal, int64_t now_ms)
{
    if (lc_portal_is_open(portal) && now_ms - portal->opened_ms >= LC_PORTAL_WINDOW_MS) {
        close_with(portal, LC_PORTAL_CLOSED_TIMEOUT);
    }
    return lc_portal_is_open(portal);
}

bool lc_portal_station_joined(lc_portal_t *portal)
{
    if (!lc_portal_is_open(portal) || portal->station_present) {
        return false;
    }
    portal->station_present = true;
    portal->session_established = false;
    return true;
}

void lc_portal_station_left(lc_portal_t *portal)
{
    portal->station_present = false;
    portal->session_established = false;
}

void lc_portal_session_established(lc_portal_t *portal)
{
    if (lc_portal_is_open(portal) && portal->station_present) {
        portal->session_established = true;
    }
}

void lc_portal_session_failed(lc_portal_t *portal)
{
    if (!lc_portal_is_open(portal)) {
        return;
    }
    portal->session_established = false;
    if (++portal->failed_sessions >= LC_PORTAL_MAX_FAILED_SESSIONS) {
        close_with(portal, LC_PORTAL_CLOSED_TOO_MANY_FAILURES);
    }
}

bool lc_portal_endpoint_allowed(const lc_portal_t *portal, lc_portal_endpoint_t endpoint)
{
    if (!lc_portal_is_open(portal) || !portal->station_present) {
        return false;
    }
    switch (endpoint) {
    case LC_PORTAL_ENDPOINT_SESSION:
        return true;
    case LC_PORTAL_ENDPOINT_CONFIG:
    case LC_PORTAL_ENDPOINT_WIFI_CONFIG:
        return portal->session_established;
    }
    return false;
}

bool lc_portal_endpoint_name_allowed(const lc_portal_t *portal, const char *name)
{
    if (strcmp(name, "prov-session") == 0) {
        return lc_portal_endpoint_allowed(portal, LC_PORTAL_ENDPOINT_SESSION);
    }
    if (strcmp(name, "prov-config") == 0) {
        return lc_portal_endpoint_allowed(portal, LC_PORTAL_ENDPOINT_WIFI_CONFIG);
    }
    if (strcmp(name, "ol-config") == 0) {
        return lc_portal_endpoint_allowed(portal, LC_PORTAL_ENDPOINT_CONFIG);
    }
    /* Not "prov-scan", not "proto-ver", nothing that reports status or settings. */
    return false;
}

void lc_portal_saved(lc_portal_t *portal)
{
    if (lc_portal_is_open(portal)) {
        close_with(portal, LC_PORTAL_CLOSED_SAVED);
    }
}
