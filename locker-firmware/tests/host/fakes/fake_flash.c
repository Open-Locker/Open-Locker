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
    size_t first = flash->cut_out_of_order ? length - landed : 0;
    for (size_t i = first; i < first + landed; ++i) {
        flash->memory[offset + i] &= bytes[i];
    }
    flash->bytes_written += landed;
    return landed == length;
}

static bool flash_erase(void *ctx, size_t offset, size_t length)
{
    fake_flash_t *flash = ctx;
    ++flash->erase_calls;
    if (offset % FAKE_FLASH_SECTOR != 0 || length % FAKE_FLASH_SECTOR != 0 ||
        offset > flash->capacity || length > flash->capacity - offset) {
        return false;
    }
    for (size_t sector = offset; sector < offset + length; sector += FAKE_FLASH_SECTOR) {
        if (flash->power_cut_armed) {
            if (flash->bytes_until_power_cut == 0) {
                memset(flash->memory + sector, 0xFF, FAKE_FLASH_SECTOR / 2);
                return false;
            }
            --flash->bytes_until_power_cut;
        }
        memset(flash->memory + sector, 0xFF,
               flash->erase_leaves_bits ? FAKE_FLASH_SECTOR - 1 : FAKE_FLASH_SECTOR);
    }
    return true;
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
        .ctx = flash,
        .capacity = flash->capacity,
        .read = flash_read,
        .write = flash_write,
        .erase = flash_erase,
    };
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
