#include <stdarg.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#include "esp_log.h"
#include "fake_rtos.h"
#include "freertos/queue.h"
#include "freertos/semphr.h"
#include "freertos/task.h"

struct fake_queue {
    unsigned length;
    unsigned item_size;
    unsigned head;
    unsigned count;
    unsigned char *items;
};

#define MAX_QUEUES 16

static struct fake_queue queues[MAX_QUEUES];
static unsigned queue_total;
static unsigned logged;
struct fake_semaphore {
    bool given;
};

static struct fake_semaphore semaphore;
static unsigned takes;
static unsigned timeouts;
static TickType_t last_take_ticks;
static TickType_t ticks_now;
static TickType_t wake_cost;
static bool fail_send;

void fake_rtos_reset(void)
{
    for (unsigned i = 0; i < queue_total; ++i) {
        free(queues[i].items);
    }
    memset(queues, 0, sizeof(queues));
    queue_total = 0;
    logged = takes = timeouts = 0;
    semaphore.given = false;
    last_take_ticks = ticks_now = wake_cost = 0;
    fail_send = false;
}

unsigned fake_log_count(void)
{
    return logged;
}

unsigned fake_take_count(void)
{
    return takes;
}

TickType_t fake_last_take_ticks(void)
{
    return last_take_ticks;
}

unsigned fake_take_timeouts(void)
{
    return timeouts;
}

void fake_rtos_set_wake_cost(TickType_t ticks)
{
    wake_cost = ticks;
}

void fake_rtos_fail_next_send(void)
{
    fail_send = true;
}

void fake_log(const char *tag, const char *format, ...)
{
    (void)tag;
    (void)format;
    ++logged;
}

QueueHandle_t xQueueCreate(UBaseType_t length, UBaseType_t item_size)
{
    if (queue_total == MAX_QUEUES) {
        return NULL;
    }
    struct fake_queue *queue = &queues[queue_total++];
    queue->length = length;
    queue->item_size = item_size;
    queue->items = calloc(length, item_size);
    return queue;
}

BaseType_t xQueueSend(QueueHandle_t queue, const void *item, TickType_t ticks)
{
    (void)ticks;
    if (fail_send) {
        fail_send = false;
        return pdFALSE;
    }
    if (queue->count == queue->length) {
        return pdFALSE;
    }
    unsigned tail = (queue->head + queue->count++) % queue->length;
    memcpy(queue->items + (size_t)tail * queue->item_size, item, queue->item_size);
    return pdTRUE;
}

BaseType_t xQueueReceive(QueueHandle_t queue, void *item, TickType_t ticks)
{
    (void)ticks;
    if (queue->count == 0) {
        return pdFALSE;
    }
    memcpy(item, queue->items + (size_t)queue->head * queue->item_size, queue->item_size);
    queue->head = (queue->head + 1) % queue->length;
    --queue->count;
    return pdTRUE;
}

UBaseType_t uxQueueMessagesWaiting(QueueHandle_t queue)
{
    return queue->count;
}

TickType_t xTaskGetTickCount(void)
{
    return ticks_now;
}

SemaphoreHandle_t xSemaphoreCreateBinary(void)
{
    semaphore.given = false;
    return &semaphore;
}

BaseType_t xSemaphoreGive(SemaphoreHandle_t handle)
{
    if (handle->given) {
        return pdFALSE;
    }
    handle->given = true;
    return pdTRUE;
}

BaseType_t xSemaphoreTake(SemaphoreHandle_t handle, TickType_t ticks)
{
    ++takes;
    last_take_ticks = ticks;
    if (handle->given) {
        handle->given = false;
        ticks_now += wake_cost;
        return pdTRUE;
    }
    ++timeouts;
    ticks_now += ticks;
    return pdFALSE;
}
