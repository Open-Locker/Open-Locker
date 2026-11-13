#include "probe.h"

/* Compiled once into each library; PROBE_NAME is that target's own definition. */
#define CAT_(a, b) a##b
#define CAT(a, b) CAT_(a, b)

#if defined(__SANITIZE_ADDRESS__)
#define INSTRUMENTED true
#elif defined(__has_feature)
#if __has_feature(address_sanitizer)
#define INSTRUMENTED true
#endif
#endif
#ifndef INSTRUMENTED
#define INSTRUMENTED false
#endif

bool CAT(lc_probe_, PROBE_NAME)(void)
{
    return INSTRUMENTED;
}
