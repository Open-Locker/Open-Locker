#pragma once

#include <stdbool.h>
#include <stdint.h>

/*
 * The decisions of the setup network (ADR-0074), with no radio, no SDK and no
 * way to reach the bus: how long it stays open, who may join, which endpoints a
 * session may reach, and when it closes for good. The adapter that runs the
 * provisioning manager asks this module before it acts and reports what
 * happened.
 *
 * Once closed, nothing reopens it in this run. Only a power-up of a unit
 * without settings opens it again (the caller decides that with
 * lc_boot_decide() and calls lc_portal_open()).
 */

#define LC_PORTAL_WINDOW_MS (10 * 60 * 1000)
#define LC_PORTAL_MAX_FAILED_SESSIONS 5

typedef enum {
    LC_PORTAL_CLOSED_NOT_OPENED = 0,
    LC_PORTAL_OPEN,
    LC_PORTAL_CLOSED_SAVED,
    LC_PORTAL_CLOSED_TIMEOUT,
    LC_PORTAL_CLOSED_TOO_MANY_FAILURES,
} lc_portal_state_t;

/* The endpoints of the provisioning manager that a session may ask for. */
typedef enum {
    /* The Security 2 handshake itself. */
    LC_PORTAL_ENDPOINT_SESSION = 0,
    /* The custom endpoint that carries the broker URL, bootstrap login and token. */
    LC_PORTAL_ENDPOINT_CONFIG,
    /* The Wi-Fi settings endpoint of the provisioning manager. */
    LC_PORTAL_ENDPOINT_WIFI_CONFIG,
} lc_portal_endpoint_t;

/* Caller-allocated. Fields are private. */
typedef struct {
    lc_portal_state_t state;
    int64_t opened_ms;
    unsigned failed_sessions;
    bool station_present;
    bool session_established;
} lc_portal_t;

void lc_portal_init(lc_portal_t *portal);

/* Opens the window at `now_ms`. Only a closed, never-opened portal can be opened. */
void lc_portal_open(lc_portal_t *portal, int64_t now_ms);

/* The window ends by itself after LC_PORTAL_WINDOW_MS. Returns whether it is open. */
bool lc_portal_tick(lc_portal_t *portal, int64_t now_ms);

/* A station asks to join. One at a time; none when closed. */
bool lc_portal_station_joined(lc_portal_t *portal);
void lc_portal_station_left(lc_portal_t *portal);

/* The Security 2 handshake of the connected station succeeded. */
void lc_portal_session_established(lc_portal_t *portal);
/* It failed. Five failures close the window. */
void lc_portal_session_failed(lc_portal_t *portal);

/*
 * Whether a request may reach `endpoint`. The handshake is open to the
 * connected station; everything else needs an established session. Nothing is
 * reachable when the portal is closed, and no endpoint exists to read status,
 * diagnostics or stored settings.
 */
bool lc_portal_endpoint_allowed(const lc_portal_t *portal, lc_portal_endpoint_t endpoint);
/* By the name the provisioning manager uses: unknown names are never allowed. */
bool lc_portal_endpoint_name_allowed(const lc_portal_t *portal, const char *name);

/* The settings are saved: the portal closes and the radio is turned over to the station. */
void lc_portal_saved(lc_portal_t *portal);

bool lc_portal_is_open(const lc_portal_t *portal);
