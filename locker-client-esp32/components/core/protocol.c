#include "locker_core.h"
#include <string.h>

bool lc_assemble(lc_assembler_t *a, const void *data, size_t length, size_t offset, size_t total)
{
    if (offset == 0) {
        a->received = 0;
        a->total = total;
        a->active = total > 0 && total <= LC_PAYLOAD_MAX;
    }
    if (!a->active || total != a->total || offset != a->received ||
        length > total - offset || (length && (!data || memchr(data, 0, length)))) {
        a->active = false;
        return false;
    }
    if (length) memcpy(a->data + offset, data, length);
    a->received += length;
    if (a->received != total) return false;
    a->data[total] = 0;
    a->active = false;
    return true;
}

static uint8_t checksum(const uint8_t *p, size_t n)
{
    uint8_t sum = 0;
    while (n--) sum ^= *p++;
    return sum;
}

bool lc_encode(uint8_t board, unsigned address, bool unlock, uint8_t out[5])
{
    if (board < 1 || board > 31 || address > 254) return false;
    out[0] = unlock ? 0x8a : 0x80;
    out[1] = board;
    out[2] = unlock ? (uint8_t)(address + 1) : 0;
    out[3] = unlock ? 0x11 : 0x33;
    out[4] = checksum(out, 4);
    return true;
}

bool lc_ack(const uint8_t *f, size_t n, uint8_t board, unsigned address)
{
    return n == 5 && board >= 1 && board <= 31 && address <= 254 &&
           f[0] == 0x8a && f[1] == board && f[2] == address + 1 &&
           (f[3] == 0 || f[3] == 0x11) && f[4] == checksum(f, 4);
}

bool lc_status(const uint8_t *f, size_t n, uint8_t board, bool opening, lc_door_t states[255])
{
    for (size_t i = 0; i < 255; ++i) states[i] = LC_UNKNOWN;
    if (n < 5 || n > LC_FRAME_MAX || f[0] != 0x80 || f[1] != board ||
        f[n - 2] != 0x33 || f[n - 1] != checksum(f, n - 1)) return false;
    size_t bytes = n - 4;
    for (size_t i = 0; i < bytes; ++i) {
        for (size_t bit = 0; bit < 8; ++bit) {
            size_t channel = (bytes - 1 - i) * 8 + bit;
            if (channel >= 255) continue;
            bool high = (f[2 + i] & (1u << bit)) != 0;
            states[channel] = high != opening ? LC_CLOSED : LC_OPEN;
        }
    }
    return true;
}
