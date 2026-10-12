#pragma once

#include <stdbool.h>

/* Whether the library was compiled with AddressSanitizer. */
bool lc_probe_under_test(void);
bool lc_probe_fakes(void);
bool lc_probe_fixture(void);
