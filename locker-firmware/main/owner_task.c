#include <string.h>

#include "esp_log.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/task.h"
#include "lc_net_wifi/wifi.h"
#include "runtime.h"

/*
 * The owner task: the one place the use cases run. Driving adapters (the MQTT
 * transport, the console, the Wi-Fi events) post events to its queue; this
 * task takes them one at a time, so no two commands, and no configuration and
 * unlock, can ever interleave.
 */

/* A slow bus exchange blocks the task for over a second while events keep arriving. */
#define QUEUE_DEPTH 32
#define TICK_MS 100
/* How long the network may be up without a valid time before it is reported. */
#define TIME_WARNING_MS 30000
/* The application keeps a few kilobytes of working copies on this stack. */
#define STACK_SIZE 12288
#define PRIORITY 6

static const char *TAG = "owner";

device_state_t device_state;

static QueueHandle_t queue;
static owner_context_t owner;
static bool network_up;
static int64_t network_up_ms;
static bool time_warned;
static bool session_started;
static int command_subscription = -1;

bool owner_post(const owner_event_t *event)
{
    /* Never waits: a full queue loses the event, and a lost command gets no unlock. */
    return queue != NULL && xQueueSend(queue, event, 0) == pdTRUE;
}

bool owner_post_app(const lc_event_t *event)
{
    owner_event_t wrapped;

    memset(&wrapped, 0, sizeof(wrapped));
    wrapped.kind = OWNER_APP;
    wrapped.u.app = *event;
    return owner_post(&wrapped);
}

static void post_to_inbox(lc_event_kind_t kind)
{
    lc_event_t event;

    memset(&event, 0, sizeof(event));
    event.kind = kind;
    (void)lc_inbox_post(owner.inbox, &event);
}

static void refresh_device_state(void)
{
    lc_network_settings_t settings;
    const lc_identity_t *identity = lc_provision_identity(owner.provision);

    device_state.enrolled = identity->enrolled;
    device_state.pending = identity->enrollment_pending;
    device_state.configured = owner.settings_store.load(owner.settings_store.ctx, &settings);
}

/* ---- Connecting ---- */

/* Starts the MQTT session once the network, the clock and an identity to use are all there. */
static void maybe_start_session(void)
{
    if (session_started || owner.bench_mode || !network_up) {
        return;
    }
    if (!lc_wifi_time_valid()) {
        /* The certificate check needs the date; a network that blocks NTP waits here for good. */
        if (!time_warned && esp_timer_get_time() / 1000 - network_up_ms >= TIME_WARNING_MS) {
            ESP_LOGW(TAG, "the network is up but the time is not set (NTP blocked?); not connecting");
            time_warned = true;
        }
        return;
    }

    /* Nothing to enroll with, or an enrollment that needs a reset: no reason to connect. */
    if (!lc_provision_wants_session(owner.provision)) {
        return;
    }
    const lc_identity_t *identity = lc_provision_identity(owner.provision);

    lc_network_settings_t settings;
    if (!owner.settings_store.load(owner.settings_store.ctx, &settings)) {
        return;
    }

    mqtt_session_config_t config;
    memset(&config, 0, sizeof(config));
    memcpy(config.broker_uri, settings.broker_url, sizeof(config.broker_uri));
    memcpy(config.client_id, identity->client_id, sizeof(config.client_id));
    if (identity->enrolled) {
        memcpy(config.username, identity->mqtt_user, sizeof(config.username));
        memcpy(config.password, identity->mqtt_password, sizeof(config.password));
        memcpy(config.locker_uuid, identity->locker_uuid, sizeof(config.locker_uuid));
    } else {
        memcpy(config.username, identity->bootstrap_user, sizeof(config.username));
        memcpy(config.password, identity->bootstrap_password, sizeof(config.password));
    }

    session_started = mqtt_session_start(&config);
    if (!session_started) {
        ESP_LOGE(TAG, "the MQTT session could not be started");
    }
    memset(&config, 0, sizeof(config));
    memset(&settings, 0, sizeof(settings));
}

static void on_transport_connected(void)
{
    if (lc_provision_identity(owner.provision)->enrolled) {
        command_subscription = mqtt_session_subscribe_commands();
        if (command_subscription < 0) {
            ESP_LOGE(TAG, "the command subscription could not be requested");
        }
    } else {
        lc_provision_on_connected(owner.provision);
    }
}

static void on_subscribed(int message_id)
{
    if (lc_provision_identity(owner.provision)->enrolled) {
        /* Commands are taken only from here on (S3-H11). */
        if (message_id == command_subscription) {
            post_to_inbox(LC_EVENT_CONNECTED);
            lc_commission_set_online(owner.commission, true);
        }
    } else if (message_id == mqtt_session_reply_subscription()) {
        lc_provision_on_subscribed(owner.provision);
    }
}

static void on_provision_reply(const lc_provisioning_reply_t *reply)
{
    lc_provision_on_reply(owner.provision, reply);

    switch (lc_provision_state(owner.provision)) {
    case LC_PROVISION_ENROLLED:
        ESP_LOGI(TAG, "enrolled; restarting to connect with the issued identity");
        refresh_device_state();
        owner.system.restart(owner.system.ctx);
        break;
    case LC_PROVISION_REJECTED:
        ESP_LOGW(TAG, "the backend refused the provisioning token");
        refresh_device_state();
        break;
    case LC_PROVISION_STORE_FAILED:
        ESP_LOGE(TAG, "the issued identity could not be stored; reset the device");
        break;
    case LC_PROVISION_IDLE:
    case LC_PROVISION_WAITING_FOR_SUBSCRIPTION:
    case LC_PROVISION_WAITING_FOR_REPLY:
    case LC_PROVISION_OUTCOME_UNKNOWN:
    default:
        break;
    }
}

/* ---- The loop ---- */

static void handle(owner_event_t *event)
{
    switch (event->kind) {
    case OWNER_APP:
        (void)lc_inbox_post(owner.inbox, &event->u.app);
        break;
    case OWNER_NETWORK_UP:
        network_up = true;
        network_up_ms = esp_timer_get_time() / 1000;
        time_warned = false;
        break;
    case OWNER_NETWORK_DOWN:
        network_up = false;
        break;
    case OWNER_TRANSPORT_CONNECTED:
        on_transport_connected();
        break;
    case OWNER_TRANSPORT_DISCONNECTED:
        if (lc_provision_identity(owner.provision)->enrolled) {
            post_to_inbox(LC_EVENT_DISCONNECTED);
            lc_commission_set_online(owner.commission, false);
        }
        break;
    case OWNER_SUBSCRIBED:
        on_subscribed(event->u.message_id);
        break;
    case OWNER_PROVISION_REPLY:
        on_provision_reply(&event->u.reply);
        break;
    case OWNER_SETUP:
        (void)lc_commission_apply(owner.commission, &event->u.setup);
        refresh_device_state();
        break;
    case OWNER_RESET:
        if (!lc_commission_reset(owner.commission)) {
            ESP_LOGE(TAG, "the reset could not be stored and was not done");
        }
        break;
    }
    /* Settings and tokens do not outlive the event they came in. */
    memset(event, 0, sizeof(*event));
}

static void owner_task(void *arg)
{
    (void)arg;
    owner_event_t event;
    int64_t last_tick_ms = esp_timer_get_time() / 1000;

    for (;;) {
        bool idle = xQueueReceive(queue, &event, pdMS_TO_TICKS(TICK_MS)) != pdTRUE;
        if (!idle) {
            handle(&event);
        }

        int64_t now_ms = esp_timer_get_time() / 1000;
        if (now_ms - last_tick_ms >= TICK_MS) {
            last_tick_ms = now_ms;
            /* One sector erase (up to about 500 ms) or one compaction per idle tick. */
            if (idle) {
                (void)lc_journal_maintain(owner.journal);
            }
            lc_provision_tick(owner.provision);
            post_to_inbox(LC_EVENT_TICK);
            maybe_start_session();
        }

        while (lc_inbox_step(owner.inbox, owner.app)) {
        }
    }
}

bool owner_start(const owner_context_t *context)
{
    owner = *context;
    queue = xQueueCreate(QUEUE_DEPTH, sizeof(owner_event_t));
    if (queue == NULL) {
        return false;
    }
    refresh_device_state();
    return xTaskCreate(owner_task, "owner", STACK_SIZE, NULL, PRIORITY, NULL) == pdPASS;
}
