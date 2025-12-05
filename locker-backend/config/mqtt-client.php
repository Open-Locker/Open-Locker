<?php

declare(strict_types=1);

use PhpMqtt\Client\MqttClient;

return [

    /*
    |--------------------------------------------------------------------------
    | Default MQTT Connection
    |--------------------------------------------------------------------------
    |
    | This setting defines the default MQTT connection returned when requesting
    | a connection without name from the facade.
    |
    */

    'default_connection' => env('MQTT_CONNECTION', 'publisher'),

    /*
    |--------------------------------------------------------------------------
    | MQTT Connections
    |--------------------------------------------------------------------------
    |
    | These are the MQTT connections used by the application. You can also open
    | an individual connection from the application itself, but all connections
    | defined here can be accessed via name conveniently.
    |
    */

    'connections' => [

        // Listener connection
        'listener' => [
            'host' => env('MQTT_BROKER_HOST', 'mqtt'),
            'port' => 1883,
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_backend_listener'),
            'clean_session' => false,
            'enable_logging' => env('MQTT_ENABLE_LOGGING', false),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'stack'),
            'repository' => \PhpMqtt\Client\Repositories\MemoryRepository::class,
            'connection_settings' => [
                // Use auth only if provided, otherwise connect anonymously in dev
                'auth' => env('MQTT_USERNAME') !== null ? [
                    'username' => env('MQTT_USERNAME'),
                    'password' => env('MQTT_PASSWORD'),
                ] : null,
                'last_will' => [
                    'topic' => 'server/status',
                    'message' => '{"status": "offline"}',
                    'quality_of_service' => 1,
                    'retain' => true,
                ],
                // Other settings...
            ],
        ],
        // Dedicated publisher connection to avoid ClientID clashes with the listener
        'publisher' => [
            'host' => env('MQTT_BROKER_HOST', 'mqtt'),
            'port' => 1883,
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => env('MQTT_PUBLISHER_CLIENT_ID', 'laravel_backend_publisher'),
            'clean_session' => false,
            'enable_logging' => env('MQTT_ENABLE_LOGGING', false),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'stack'),
            'connection_settings' => [
                'auth' => env('MQTT_USERNAME') !== null ? [
                    'username' => env('MQTT_USERNAME'),
                    'password' => env('MQTT_PASSWORD'),
                ] : null,
            ],
        ],

        // Provisioning connection for testing
        'provisioning' => [
            'host' => env('MQTT_BROKER_HOST', 'mqtt'),
            'port' => 1883,
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => null, // Provisioning clients will use a randomized client ID per session.
            'clean_session' => false, // Use a persistent session to reliably receive the reply.
            'enable_logging' => env('MQTT_ENABLE_LOGGING', false),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'stack'),
            'connection_settings' => [
                'auth' => env('MQTT_PROVISIONING_USERNAME') !== null ? [
                    'username' => env('MQTT_PROVISIONING_USERNAME'),
                    'password' => env('MQTT_PROVISIONING_PASSWORD'),
                ] : null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Users (Backend & Provisioning)
    |--------------------------------------------------------------------------
    |
    | Credentials used to provision the VerneMQ DB auth entries for the
    | backend publisher/listener and the provisioning client. Loaded here so
    | they are available from cached config at runtime.
    |
    */

    'system' => [
        'backend_username' => env('MQTT_USERNAME', 'laravel_backend'),
        'backend_password' => env('MQTT_PASSWORD'),
        'provisioning_username' => env('MQTT_PROVISIONING_USERNAME', 'provisioning_client'),
        'provisioning_password' => env('MQTT_PROVISIONING_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | VerneMQ Webhooks Auth
    |--------------------------------------------------------------------------
    |
    | Credentials VerneMQ uses to call our webhook endpoints. Read via config
    | (backed by env) instead of env() directly in code.
    |
    */

    'webhooks' => [
        'user' => env('MOSQ_HTTP_USER'),
        'pass' => env('MOSQ_HTTP_PASS'),
    ],

];
