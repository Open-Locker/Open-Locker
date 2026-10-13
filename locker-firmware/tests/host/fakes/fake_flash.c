#include "fake_flash.h"

#include <assert.h>
#include <string.h>

static bool flash_read(void *ctx, size_t offset, void *out, size_t length)
{
    fake_flash_t *flash = ctx;
    if (flash->reads_until_failure > 0 && --flash->reads_until_failure == 0) {
        return false;
    }
    if (offset > flash->capacity || length > flash->capacity - offset) {
        return false;
    }
    memcpy(out, flash->memory + offset, length);
    return true;
}

static bool flash_write(void *ctx, size_t offset, const void *data, size_t length)
{
    fake_flash_t *flash = ctx;
    ++flash->write_calls;
    if (offset > flash->capacity || length > flash->capacity - offset) {
        return false;
    }

    size_t landed = length;
    if (flash->power_cut_armed) {
        if (landed > flash->bytes_until_power_cut) {
            landed = flash->bytes_until_power_cut;
        }
        flash->bytes_until_power_cut -= landed;
    }
    const uint8_t *bytes = data;
    for (size_t i = 0; i < landed; ++i) {
        flash->memory[offset + i] &= bytes[i];
    }
    flash->bytes_written += landed;
    return landed == length;
}

void fake_flash_init(fake_flash_t *flash, size_t capacity)
{
    assert(capacity <= FAKE_FLASH_MAX);
    memset(flash, 0, sizeof(*flash));
    memset(flash->memory, 0xFF, sizeof(flash->memory));
    flash->capacity = capacity;
}

lc_flash_io_t fake_flash_io(fake_flash_t *flash)
{
    return (lc_flash_io_t){
        .ctx = flash, .capacity = flash->capacity, .read = flash_read, .write = flash_write};
}

void fake_flash_cut_power_after(fake_flash_t *flash, size_t bytes)
{
    flash->power_cut_armed = true;
    flash->bytes_until_power_cut = bytes;
}

void fake_flash_restore_power(fake_flash_t *flash)
{
    flash->power_cut_armed = false;
}

void fake_flash_fail_read(fake_flash_t *flash, unsigned nth)
{
    flash->reads_until_failure = nth;
}
