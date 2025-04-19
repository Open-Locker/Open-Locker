<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenLocker\PhpModbusFfi\Contracts\ModbusClient;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusConfigurationException;
use OpenLocker\PhpModbusFfi\ModbusRtuConnection;
use OpenLocker\PhpModbusFfi\ModbusTcpConnection;

class ModbusServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            // Pfad zur Konfigurationsdatei anpassen, falls nötig
            __DIR__.'/../../config/modbus.php', 'modbus'
        );

        // Binde das Interface an eine Factory-Funktion, die die konkrete Instanz erstellt
        $this->app->singleton(ModbusClient::class, function () {
            // Hole den Standard-Treiber aus der Konfiguration, Standardwert 'tcp'
            $driver = config('modbus.default', 'tcp');

            // Bestimme den Pfad zur libmodbus-Bibliothek
            // Prüfe zuerst die spezifische Treiberkonfiguration, dann die des anderen Treibers als Fallback
            $libraryPath = config("modbus.drivers.{$driver}.library_path");
            if ($libraryPath === null) {
                $otherDriver = ($driver === 'tcp') ? 'rtu' : 'tcp';
                // Hole den Pfad aus der Konfiguration des anderen Treibers, Standardwert null
                $libraryPath = config("modbus.drivers.{$otherDriver}.library_path");
            }
            // config() gibt null zurück, wenn der Schlüssel nicht existiert und kein Standardwert angegeben ist.

            switch ($driver) {
                case 'tcp':
                    // Hole die IP-Adresse; kein Standardwert, da erforderlich
                    $ip = config('modbus.drivers.tcp.ip');
                    if (empty($ip)) {
                        // Wirf Exception, wenn die IP fehlt
                        throw new ModbusConfigurationException('Modbus TCP IP address is not configured in config/modbus.php drivers.tcp section.');
                    }

                    // Erstelle die TCP-Verbindung mit Werten aus der Konfig (mit Standardwerten im Helper)
                    return new ModbusTcpConnection(
                        $ip,
                        config('modbus.drivers.tcp.port', 502), // Standardwert 502 für Port
                        $libraryPath // Übergebe den ermittelten Bibliothekspfad
                    );

                case 'rtu':
                    // Hole den Gerätepfad; kein Standardwert, da erforderlich
                    $device = config('modbus.drivers.rtu.device');
                    if (empty($device)) {
                        // Wirf Exception, wenn der Gerätepfad fehlt
                        throw new ModbusConfigurationException('Modbus RTU device path is not configured in config/modbus.php drivers.rtu section.');
                    }

                    // Erstelle die RTU-Verbindung mit Werten aus der Konfig (mit Standardwerten im Helper)
                    return new ModbusRtuConnection(
                        $device,
                        config('modbus.drivers.rtu.baud', 9600),
                        config('modbus.drivers.rtu.parity', 'N'),
                        config('modbus.drivers.rtu.data_bits', 8),
                        config('modbus.drivers.rtu.stop_bits', 1),
                        $libraryPath // Übergebe den ermittelten Bibliothekspfad
                    );

                default:
                    // Wirf Exception für ungültige Treiber
                    throw new InvalidArgumentException("Unsupported Modbus driver [{$driver}]. Supported drivers are 'tcp' and 'rtu'.");
            }
        });

        // Optional: Aliase für einfachere Nutzung ohne Interface-Typehint (weniger empfohlen)
        // $this->app->alias(ModbusClient::class, 'modbus.connection');
    }

    public function boot()
    {
        $this->publishes([
            // Pfad zur Konfigurationsdatei anpassen, falls nötig
            __DIR__.'/../../config/modbus.php' => config_path('modbus.php'),
        ], 'config');
    }
}
