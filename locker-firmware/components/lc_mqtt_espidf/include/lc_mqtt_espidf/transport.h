#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/*
 * MQTT 3.1.1 over TLS on esp-mqtt. This is only a transport: topics and JSON
 * belong to the codec, and the composition root puts the two together, so no
 * adapter depends on another.
 *
 * The broker certificate and its host name are always verified, against the
 * CA bundle built into the firmware. There is no option to turn that off, and
 * the build fails if the SDK is configured to.
 *
 * The session is persistent and uses a stable client id, publishes are QoS 1,
 * and a Last Will is registered at connect. Handlers run on the esp-mqtt task:
 * they copy what they are given and hand it on; they do not block.
 */

typedef struct {
    void *ctx;
    void (*on_connected)(void *ctx);
    void (*on_disconnected)(void *ctx);
    /* The broker confirmed a subscription asked for with lc_mqtt_transport_subscribe(). */
    void (*on_subscribed)(void *ctx, int message_id);
    /* The broker acknowledged (PUBACK) a publish. */
    void (*on_published)(void *ctx, int message_id);
    /* A publish was dropped from the outbox without being acknowledged. */
    void (*on_dropped)(void *ctx, int message_id);
    /*
     * One fragment of an inbound message. `topic` is NULL for every fragment
     * after the first. `total` is the length of the whole payload.
     */
    void (*on_data)(void *ctx, const char *topic, const char *data, size_t length, size_t offset,
                    size_t total);
} lc_mqtt_handlers_t;

typedef struct {
    /* mqtts://host:port; mqtt:// only with CONFIG_LC_ALLOW_PLAINTEXT_MQTT. */
    const char *broker_uri;
    const char *client_id;
    const char *username;
    const char *password;
    const char *will_topic;
    const char *will_payload;
    size_t will_length;
    uint32_t keepalive_seconds;
} lc_mqtt_transport_config_t;

/* Starts connecting and keeps reconnecting. The strings are copied. */
bool lc_mqtt_transport_start(const lc_mqtt_transport_config_t *config,
                             const lc_mqtt_handlers_t *handlers);


/* The message id of the request, or a negative number when it could not be made. */
int lc_mqtt_transport_subscribe(const char *topic, int qos);

/*
 * Queues a publish without waiting for the broker. The message id, which comes
 * back in on_published or on_dropped, or a negative number when it was refused.
 */
int lc_mqtt_transport_publish(const char *topic, const char *payload, size_t length, int qos,
                              bool retain);
