#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <string.h>

/*
 * Bounded string copy. False, with `dst` left as an empty string, when `src`
 * does not fit in `capacity` bytes including the terminator.
 */
static inline bool lc_text_copy(char *dst, size_t capacity, const char *src)
{
    size_t length = strlen(src);
    if (capacity == 0) {
        return false;
    }
    if (length >= capacity) {
        dst[0] = '\0';
        return false;
    }
    memcpy(dst, src, length + 1);
    return true;
}
