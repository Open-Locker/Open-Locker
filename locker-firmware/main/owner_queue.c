#include <string.h>

#include "esp_log.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/semphr.h"
#include "freertos/task.h"
#include "owner_queue.h"

/*
 * Commands and the two large events live in static slots; what travels through
 * a FreeRTOS queue is the slot's index, one byte. A producer takes a free index
 * without waiting, fills the slot in place and posts the index; the owner task
 * hands the slot back after the handler returned. So no command is copied, and
 * a full pool refuses the next one instead of pushing another event out.
 */

static const char *TAG = "owner_queue";

typedef struct {
    QueueHandle_t idle;
    QueueHandle_t ready;
} slots_t;

static QueueHandle_t control;
static slots_t command_slots;
static slots_t setup_slots;
static slots_t reply_slots;
static lc_command_t commands[OWNER_COMMAND_SLOTS];
static owner_setup_t setup;
static lc_provisioning_reply_t reply;
/* Given by every post, taken by the owner task when it has nothing to do. Binary: it says "look", not how many. */
static SemaphoreHandle_t wakeup;

static bool slots_init(slots_t *slots, uint8_t count)
{
    slots->idle = xQueueCreate(count, sizeof(uint8_t));
    slots->ready = xQueueCreate(count, sizeof(uint8_t));
    if (slots->idle == NULL || slots->ready == NULL) {
        return false;
    }
    for (uint8_t index = 0; index < count; ++index) {
        if (xQueueSend(slots->idle, &index, 0) != pdTRUE) {
            return false;
        }
    }
    return true;
}

static bool slots_acquire(slots_t *slots, uint8_t *index)
{
    return slots->idle != NULL && xQueueReceive(slots->idle, index, 0) == pdTRUE;
}

static void slots_release(slots_t *slots, uint8_t index)
{
    (void)xQueueSend(slots->idle, &index, 0);
}

static void wake(void)
{
    if (wakeup != NULL) {
        (void)xSemaphoreGive(wakeup);
    }
}

/*
 * Cannot fail while there are as many places in `ready` as slots. If it does,
 * the caller zeroes the payload and only then releases the slot: once released,
 * another producer may fill it.
 */
static bool slots_submit(slots_t *slots, uint8_t index)
{
    if (xQueueSend(slots->ready, &index, 0) != pdTRUE) {
        return false;
    }
    wake();
    return true;
}

static bool slots_take(slots_t *slots, uint8_t *index)
{
    return xQueueReceive(slots->ready, index, 0) == pdTRUE;
}

bool owner_queue_init(void)
{
    memset(commands, 0, sizeof(commands));
    memset(&setup, 0, sizeof(setup));
    memset(&reply, 0, sizeof(reply));
    control = xQueueCreate(OWNER_CONTROL_DEPTH, sizeof(owner_control_t));
    wakeup = xSemaphoreCreateBinary();
    return control != NULL && wakeup != NULL && slots_init(&command_slots, OWNER_COMMAND_SLOTS) &&
           slots_init(&setup_slots, 1) && slots_init(&reply_slots, 1);
}

/* ---- Producers ---- */

bool owner_post(const owner_control_t *event)
{
    /* Never waits: a full queue loses the event. */
    bool queued = control != NULL && xQueueSend(control, event, 0) == pdTRUE;
    if (queued) {
        wake();
    }
    return queued;
}

bool owner_post_app(lc_event_kind_t kind, uint32_t value)
{
    const owner_control_t event = {.kind = OWNER_APP, .app_kind = kind, .value = value};
    return owner_post(&event);
}

lc_command_t *owner_command_acquire(void)
{
    uint8_t index;

    if (!slots_acquire(&command_slots, &index)) {
        /* No unlock for a lost command, and the backend times out. */
        ESP_LOGW(TAG, "command pool full, command dropped");
        return NULL;
    }
    return &commands[index];
}

bool owner_command_submit(lc_command_t *slot)
{
    uint8_t index = (uint8_t)(slot - commands);

    if (!slots_submit(&command_slots, index)) {
        owner_command_discard(slot);
        return false;
    }
    return true;
}

void owner_command_discard(lc_command_t *slot)
{
    memset(slot, 0, sizeof(*slot));
    slots_release(&command_slots, (uint8_t)(slot - commands));
}

bool owner_post_setup(lc_commission_channel_t channel, const lc_setup_request_t *request)
{
    uint8_t index;

    if (!slots_acquire(&setup_slots, &index)) {
        ESP_LOGW(TAG, "a setup request is still waiting, request dropped");
        return false;
    }
    setup.channel = channel;
    setup.request = *request;
    if (!slots_submit(&setup_slots, index)) {
        memset(&setup, 0, sizeof(setup));
        slots_release(&setup_slots, index);
        return false;
    }
    return true;
}

lc_provisioning_reply_t *owner_reply_acquire(void)
{
    uint8_t index;

    if (!slots_acquire(&reply_slots, &index)) {
        ESP_LOGW(TAG, "a provisioning reply is still waiting, reply dropped");
        return NULL;
    }
    return &reply;
}

bool owner_reply_submit(void)
{
    if (!slots_submit(&reply_slots, 0)) {
        owner_reply_discard(&reply);
        return false;
    }
    return true;
}

void owner_reply_discard(lc_provisioning_reply_t *slot)
{
    memset(slot, 0, sizeof(*slot));
    slots_release(&reply_slots, 0);
}

/* ---- The owner task ---- */

static bool handle_waiting(const owner_handlers_t *handlers)
{
    owner_control_t event;
    uint8_t index;
    bool handled = false;

    /* A bounded drain: a producer posting as fast as the task handles cannot hold off the command. */
    for (unsigned n = 0; n < OWNER_CONTROL_DEPTH && xQueueReceive(control, &event, 0) == pdTRUE; ++n) {
        handlers->control(&event);
        handled = true;
    }
    if (slots_take(&setup_slots, &index)) {
        handlers->setup(&setup);
        /* Settings and tokens do not outlive the event they came in. */
        memset(&setup, 0, sizeof(setup));
        slots_release(&setup_slots, index);
        handled = true;
    }
    if (slots_take(&reply_slots, &index)) {
        handlers->reply(&reply);
        memset(&reply, 0, sizeof(reply));
        slots_release(&reply_slots, index);
        handled = true;
    }
    if (slots_take(&command_slots, &index)) {
        handlers->command(&commands[index]);
        /* Returned after the handler, whatever it did: a duplicate or an offline refusal included. */
        memset(&commands[index], 0, sizeof(commands[index]));
        slots_release(&command_slots, index);
        handled = true;
    }
    return handled;
}

bool owner_queue_step(const owner_handlers_t *handlers, uint32_t wait_ms)
{
    const TickType_t start = xTaskGetTickCount();
    const TickType_t budget = pdMS_TO_TICKS(wait_ms);

    if (handle_waiting(handlers)) {
        return true;
    }
    for (;;) {
        TickType_t elapsed = xTaskGetTickCount() - start;
        if (elapsed >= budget) {
            return false;
        }
        bool woken = xSemaphoreTake(wakeup, budget - elapsed) == pdTRUE;
        /* After a timeout this is the second look, for a post that raced the take. */
        if (handle_waiting(handlers)) {
            return true;
        }
        if (!woken) {
            return false;
        }
    }
}
