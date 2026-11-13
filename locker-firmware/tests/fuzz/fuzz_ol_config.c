#include <stddef.h>
#include <stdint.h>

#include "lc_softap_portal/ol_config.h"

/*
 * libFuzzer target for the `ol-config` parser. Build it with clang:
 *
 *   clang -g -fsanitize=fuzzer,address,undefined -Icomponents/lc_domain/include \
 *     -Icomponents/lc_softap_portal/include tests/fuzz/fuzz_ol_config.c \
 *     components/lc_softap_portal/ol_config.c -o fuzz_ol_config
 *
 * The host gate compiles this file (so it cannot rot) and runs a fixed-seed
 * random test over the same code (test_ol_config); it does not run libFuzzer.
 */
int LLVMFuzzerTestOneInput(const uint8_t *data, size_t size)
{
    lc_collector_t collector;

    lc_collector_init(&collector);
    (void)lc_collector_ol_config(&collector, data, size);
    lc_collector_clear(&collector);
    return 0;
}
