#include "lc_domain/version.h"

#include <string.h>

#include "lc_domain/firmware.h"

static bool is_digit(char c)
{
    return c >= '0' && c <= '9';
}

static bool is_identifier_character(char c)
{
    return is_digit(c) || (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || c == '-';
}

/* A numeric identifier has no leading zero (semver.org, rules 2 and 9). */
static bool is_numeric_identifier(const char *text, size_t length)
{
    return length > 0 && !(text[0] == '0' && length > 1);
}

/* The length of the run of digits at `text`. */
static size_t digits_at(const char *text)
{
    size_t length = 0;
    while (is_digit(text[length])) {
        ++length;
    }
    return length;
}

bool lc_version_is_valid(const char *text)
{
    if (strlen(text) > LC_FIRMWARE_VERSION_MAX) {
        return false;
    }
    for (int part = 0; part < 3; ++part) {
        size_t length = digits_at(text);
        if (!is_numeric_identifier(text, length)) {
            return false;
        }
        text += length;
        if (part < 2 && *text++ != '.') {
            return false;
        }
    }
    if (*text == '\0') {
        return true;
    }
    if (*text++ != '-') {
        return false;
    }
    for (;;) {
        size_t length = 0;
        while (is_identifier_character(text[length])) {
            ++length;
        }
        /* Not empty, and a number without a leading zero. */
        if (length == 0 || (digits_at(text) == length && !is_numeric_identifier(text, length))) {
            return false;
        }
        text += length;
        if (*text == '\0') {
            return true;
        }
        if (*text++ != '.') {
            return false;
        }
    }
}

/* Compares two runs of digits as numbers of any length. */
static int compare_numbers(const char *a, size_t a_length, const char *b, size_t b_length)
{
    while (a_length > 1 && *a == '0') {
        ++a;
        --a_length;
    }
    while (b_length > 1 && *b == '0') {
        ++b;
        --b_length;
    }
    if (a_length != b_length) {
        return a_length < b_length ? -1 : 1;
    }
    return memcmp(a, b, a_length);
}

/* The length of the identifier at `text`, up to a dot or the end. */
static size_t identifier_length(const char *text)
{
    size_t length = 0;
    while (text[length] != '.' && text[length] != '\0') {
        ++length;
    }
    return length;
}

/* Pre-release identifiers: numbers below text, numbers as numbers, fewer identifiers lower. */
static int compare_pre_release(const char *a, const char *b)
{
    for (;;) {
        if (*a == '\0' || *b == '\0') {
            return (*a != '\0') - (*b != '\0');
        }
        size_t a_length = identifier_length(a);
        size_t b_length = identifier_length(b);
        bool a_numeric = digits_at(a) == a_length;
        bool b_numeric = digits_at(b) == b_length;
        int order;
        if (a_numeric && b_numeric) {
            order = compare_numbers(a, a_length, b, b_length);
        } else if (a_numeric != b_numeric) {
            order = a_numeric ? -1 : 1;
        } else {
            size_t common = a_length < b_length ? a_length : b_length;
            order = memcmp(a, b, common);
            if (order == 0 && a_length != b_length) {
                order = a_length < b_length ? -1 : 1;
            }
        }
        if (order != 0) {
            return order < 0 ? -1 : 1;
        }
        a += a_length + (a[a_length] == '.' ? 1 : 0);
        b += b_length + (b[b_length] == '.' ? 1 : 0);
    }
}

int lc_version_compare(const char *a, const char *b)
{
    for (int part = 0; part < 3; ++part) {
        size_t a_length = digits_at(a);
        size_t b_length = digits_at(b);
        int order = compare_numbers(a, a_length, b, b_length);
        if (order != 0) {
            return order < 0 ? -1 : 1;
        }
        a += a_length + (part < 2 ? 1 : 0);
        b += b_length + (part < 2 ? 1 : 0);
    }
    /* A release is above its pre-releases. */
    bool a_pre_release = *a == '-';
    bool b_pre_release = *b == '-';
    if (!a_pre_release || !b_pre_release) {
        return a_pre_release == b_pre_release ? 0 : (a_pre_release ? -1 : 1);
    }
    return compare_pre_release(a + 1, b + 1);
}
