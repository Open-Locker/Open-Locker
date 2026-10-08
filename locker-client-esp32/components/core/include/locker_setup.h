#pragma once
#include "locker_core.h"
#define LC_SETUP_MAX 2048
typedef struct {
    char ssid[33], wifi_password[65], broker[193];
    char bootstrap_user[129], bootstrap_password[129], token[129];
} lc_setup_t;
typedef struct { char line[LC_SETUP_MAX + 1]; size_t length; bool discard; } lc_usb_line_t;
/* Returns 1 for a complete line, -1 for rejected input, 0 while assembling.
 * Rejected lines are discarded through newline, never executed as a suffix. */
int lc_usb_feed(lc_usb_line_t *input, unsigned char byte);
bool lc_setup_parse(const char *text, bool enrolled, bool pending, lc_setup_t *out);
