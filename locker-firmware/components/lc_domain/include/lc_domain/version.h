#pragma once

#include <stdbool.h>

/* Release versions: x.y.z with an optional "-" and pre-release identifiers (semantic versioning). */

/* At most LC_FIRMWARE_VERSION_MAX characters, digits dot digits dot digits, then optionally
 * "-" and identifiers of [0-9A-Za-z-] separated by dots. */
bool lc_version_is_valid(const char *text);

/* The precedence of two valid versions: below zero, zero or above zero. */
int lc_version_compare(const char *a, const char *b);
