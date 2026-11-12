#include "esp_log.h"
#include "esp_partition.h"
#include "lc_storage_nvs/storage.h"

static const char *TAG = "lc_partition";

bool lc_partition_open(lc_partition_t *partition, const char *label)
{
    /* The journal partition has a custom subtype, so any subtype is accepted. */
    const esp_partition_t *found =
        esp_partition_find_first(ESP_PARTITION_TYPE_DATA, ESP_PARTITION_SUBTYPE_ANY, label);

    if (found == NULL) {
        ESP_LOGE(TAG, "partition %s not found", label);
        partition->partition = NULL;
        partition->capacity = 0;
        return false;
    }
    partition->partition = found;
    partition->capacity = found->size;
    return true;
}

bool lc_partition_read(void *ctx, size_t offset, void *out, size_t length)
{
    const lc_partition_t *partition = ctx;
    return partition->partition != NULL &&
           esp_partition_read(partition->partition, offset, out, length) == ESP_OK;
}

bool lc_partition_write(void *ctx, size_t offset, const void *data, size_t length)
{
    const lc_partition_t *partition = ctx;
    /* Never erases: the journal only clears bits of bytes that are still erased. */
    return partition->partition != NULL &&
           esp_partition_write(partition->partition, offset, data, length) == ESP_OK;
}

bool lc_partition_erase(void *ctx, size_t offset, size_t length)
{
    const lc_partition_t *partition = ctx;
    return partition->partition != NULL &&
           esp_partition_erase_range(partition->partition, offset, length) == ESP_OK;
}
