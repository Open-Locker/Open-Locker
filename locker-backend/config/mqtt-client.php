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

    'default_connection' => env('MQTT_CONNECTION', 'default'),

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

        'default' => [
            'host' => env('MQTT_HOST', '127.0.0.1'),
            'port' => env('MQTT_PORT', 1883),
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => env('MQTT_CLIENT_ID', 'laravel_backend_client'),
            'clean_session' => false,
            'enable_logging' => env('MQTT_ENABLE_LOGGING', false),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'stack'),
            'repository' => \PhpMqtt\Client\Repositories\MemoryRepository::class,
            'connection_settings' => [
                'auth' => [
                    'username' => env('MQTT_USERNAME', 'laravel_backend'),
                    'password' => env('MQTT_PASSWORD'),
                ],
                'last_will' => [
                    'topic' => 'server/status',
                    'message' => '{"status": "offline"}',
                    'quality_of_service' => 1,
                    'retain' => true,
                ],
                // Other settings...
            ],
        ],

        'provisioning' => [
            'host' => env('MQTT_HOST', '127.0.0.1'),
            'port' => env('MQTT_PORT', 1883),
            'protocol' => MqttClient::MQTT_3_1_1,
            'client_id' => null, // This will be set dynamically by the test command.
            'clean_session' => false, // Use a persistent session to reliably receive the reply.
            'enable_logging' => env('MQTT_ENABLE_LOGGING', false),
            'log_channel' => env('MQTT_LOG_CHANNEL', 'stack'),
            'connection_settings' => [
                'auth' => [
                    'username' => env('MQTT_PROVISIONING_USERNAME'),
                    'password' => env('MQTT_PROVISIONING_PASSWORD'),
                ],
            ],
        ],

    ],

];
