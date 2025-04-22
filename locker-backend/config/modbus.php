<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Modbus Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default Modbus connection driver that gets
    | instantiated when requesting the ModbusClient interface. Supported: "tcp", "rtu".
    | Corresponding environment variable: MODBUS_DRIVER
    |
    */
    'default' => env('MODBUS_DRIVER', 'tcp'), // 'tcp' or 'rtu'

    /*
    |--------------------------------------------------------------------------
    | Modbus Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the Modbus connections setup for your application.
    |
    */
    'drivers' => [

        'tcp' => [
            'ip' => env('MODBUS_TCP_IP', '127.0.0.1'),
            'port' => env('MODBUS_TCP_PORT', 502),
            'library_path' => env('MODBUS_LIB_PATH', '/lib/aarch64-linux-gnu/libmodbus.so.5'),
            'default_slave' => env('MODBUS_TCP_SLAVE', 1), // Optional: Default Slave ID
        ],

        'rtu' => [
            'device' => env('MODBUS_RTU_DEVICE', '/dev/ttyUSB0'), // Anpassen! Z.B. 'COM3' für Windows
            'baud' => env('MODBUS_RTU_BAUD', 9600),
            'parity' => env('MODBUS_RTU_PARITY', 'N'), // N, E, O
            'data_bits' => env('MODBUS_RTU_DATA_BITS', 8), // 7 or 8
            'stop_bits' => env('MODBUS_RTU_STOP_BITS', 1), // 1 or 2
            'library_path' => env('MODBUS_LIB_PATH', '/lib/aarch64-linux-gnu/libmodbus.so.5'),
            'default_slave' => env('MODBUS_RTU_SLAVE', 1), // Optional: Default Slave ID
        ],
    ],

    // Optional: Globale Einstellungen, falls benötigt
    // 'global_settings' => [
    //     'debug' => env('MODBUS_DEBUG', false),
    // ]

];
