#pragma once

#include <stdbool.h>

/* Who the device is to the broker, and how it reaches the network. */

#define LC_CLIENT_ID_MAX 39
#define LC_CREDENTIAL_MAX 128
#define LC_UUID_SIZE 37
#define LC_SSID_MAX 32
#define LC_WIFI_PASSWORD_MIN 8
#define LC_WIFI_PASSWORD_MAX 64
#define LC_BROKER_URL_MAX 192

typedef struct {
    char client_id[LC_CLIENT_ID_MAX + 1];
    bool enrolled;
    /* Set before the one-time token is sent; cleared when its reply is stored. */
    bool enrollment_pending;
    /* Only until enrollment succeeds. */
    char bootstrap_user[LC_CREDENTIAL_MAX + 1];
    char bootstrap_password[LC_CREDENTIAL_MAX + 1];
    char token[LC_CREDENTIAL_MAX + 1];
    /* Issued by the backend. */
    char mqtt_user[LC_CREDENTIAL_MAX + 1];
    char mqtt_password[LC_CREDENTIAL_MAX + 1];
    char locker_uuid[LC_UUID_SIZE];
} lc_identity_t;

typedef struct {
    bool configured;
    char ssid[LC_SSID_MAX + 1];
    char wifi_password[LC_WIFI_PASSWORD_MAX + 1];
    /* Must start with mqtts://; mqtt:// only on a development build (ADR-0071). */
    char broker_url[LC_BROKER_URL_MAX + 1];
} lc_network_settings_t;

/* What an installer submits. The last three fields matter only before enrollment. */
typedef struct {
    char ssid[LC_SSID_MAX + 1];
    char wifi_password[LC_WIFI_PASSWORD_MAX + 1];
    char broker_url[LC_BROKER_URL_MAX + 1];
    char bootstrap_user[LC_CREDENTIAL_MAX + 1];
    char bootstrap_password[LC_CREDENTIAL_MAX + 1];
    char token[LC_CREDENTIAL_MAX + 1];
} lc_setup_request_t;

/* The answer of the backend to a registration. */
typedef struct {
    bool success;
    char mqtt_user[LC_CREDENTIAL_MAX + 1];
    char mqtt_password[LC_CREDENTIAL_MAX + 1];
    char locker_uuid[LC_UUID_SIZE];
} lc_provisioning_reply_t;
