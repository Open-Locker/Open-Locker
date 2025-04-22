# PHP Modbus FFI Bibliothek (Lokales Paket)

Dieses Paket stellt eine PHP-Bibliothek zur Verfügung, um mit Modbus-Geräten über TCP und RTU zu kommunizieren. Es nutzt die PHP FFI (Foreign Function Interface) Erweiterung, um die C-Bibliothek `libmodbus` anzubinden.

Dieses Paket ist als **lokales Composer-Paket** für die Verwendung innerhalb eines spezifischen Projekts oder Monorepos konzipiert. Der Paketname ist `open-locker/php-modbus-ffi`.

## Anforderungen

* PHP >= 8.2
* PHP-Erweiterung `ext-ffi` aktiviert
* `libmodbus` C-Bibliothek auf dem System installiert, auf dem PHP läuft (Laufzeitbibliothek, z.B. `libmodbus5` unter Debian/Ubuntu).
    * Für die **Entwicklungsumgebung** (z.B. Docker/Sail) wird das `-dev`-Paket benötigt (`libmodbus-dev`), um die Installation im Container-Build zu ermöglichen.
    * Für die **Produktionsumgebung** genügt die Laufzeitbibliothek (z.B. `libmodbus5`).

## Installation (Als lokales Paket)

Dieses Paket wird nicht über Packagist installiert. Stattdessen wird es über ein lokales Pfad-Repository in der `composer.json` des Hauptprojekts eingebunden:

1.  **Paket-Code:** Stellen Sie sicher, dass sich der Quellcode dieses Pakets im Verzeichnis `packages/OpenLocker/php-modbus-ffi` (relativ zum Root des Hauptprojekts) befindet. *Passen Sie diesen Pfad ggf. an Ihre Struktur an.*
2.  **Haupt `composer.json` anpassen:** Fügen Sie das lokale Repository und die Anforderung hinzu:

    ```json
    {
        // ...
        "repositories": [
            {
                "type": "path",
                "url": "packages/OpenLocker/php-modbus-ffi" // Pfad anpassen!
            }
            // ... ggf. weitere Repositories ...
        ],
        "require": {
            // ... andere Abhängigkeiten ...
            "open-locker/php-modbus-ffi": "@dev"
        }
        // ...
    }
    ```

3.  **Composer Update:** Führen Sie im Hauptverzeichnis des Projekts `composer update open-locker/php-modbus-ffi` aus (oder `composer install`, falls noch nicht geschehen). Dadurch wird das Paket über den Autoloader verfügbar.

## Konfiguration

Da die Laravel-spezifische Integration entfernt wurde, müssen Sie die Modbus-Verbindungsklassen (`ModbusTcpConnection` oder `ModbusRtuConnection`) manuell in Ihrem Code instanziieren und konfigurieren.

* **Parameter:** Übergeben Sie die notwendigen Parameter (IP/Port für TCP; Device, Baud, Parity etc. für RTU) an den Konstruktor der jeweiligen Klasse.
* **Bibliothekspfad (Optional):** Der letzte Parameter im Konstruktor erlaubt die Angabe eines spezifischen Pfades zur `libmodbus.so`/`.dll`/`.dylib`-Datei. Wenn `null` übergeben wird (Standard), versucht die Bibliothek, den Standardnamen (`libmodbus.so.5` etc.) über die Systempfade zu laden.
* **Konfigurationsmanagement:** Es wird empfohlen, die Verbindungsparameter nicht fest im Code zu verdrahten. Nutzen Sie stattdessen Umgebungsvariablen, eine separate Konfigurationsdatei oder einen anderen Mechanismus Ihrer Wahl, um diese Werte zu verwalten.

## Verwendung (Beispiel in PHP)

Hier ist ein generisches Beispiel, wie Sie die Bibliothek direkt verwenden können:

```php
<?php

// Composer Autoloader einbinden (Pfad anpassen!)
require_once __DIR__ . '/../vendor/autoload.php';

// Korrekte Namespaces verwenden!
use OpenLocker\PhpModbusFfi\ModbusTcpConnection;
use OpenLocker\PhpModbusFfi\ModbusRtuConnection;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusException;

// --- TCP Beispiel ---
echo "--- Modbus TCP Example ---\n";
$tcpIp = '127.0.0.1'; // IP des Modbus TCP Servers
$tcpPort = 502;
$tcpSlaveId = 1;
// Optional: Pfad zur Bibliothek, falls nicht im Standardpfad
$libraryPath = null; // oder z.B. '/opt/lib/libmodbus.so.5'

try {
    $modbusTcp = new ModbusTcpConnection($tcpIp, $tcpPort, $libraryPath);
    $modbusTcp->setSlave($tcpSlaveId);
    $modbusTcp->connect();
    echo "TCP Connected to {$tcpIp}:{$tcpPort}\n";

    $registers = $modbusTcp->readHoldingRegisters(100, 5);
    echo "TCP Read Registers (100-104): ";
    print_r($registers);

    $modbusTcp->close();
    echo "TCP Connection closed.\n";

} catch (ModbusException $e) {
    echo "TCP Error: " . get_class($e) . " - " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "General TCP Error: " . $e->getMessage() . "\n";
}

echo "\n";

// --- RTU Beispiel ---
echo "--- Modbus RTU Example ---\n";
$rtuDevice = '/dev/ttyUSB0'; // Anpassen! (z.B. 'COM3' unter Windows)
$rtuSlaveId = 1;

// Prüfen, ob das Gerät existiert (optional, nur zur Info)
if (!file_exists($rtuDevice) && substr(PHP_OS, 0, 3) !== 'WIN') {
    echo "RTU Warning: Device '{$rtuDevice}' not found. Skipping RTU example.\n";
} else {
    try {
        $modbusRtu = new ModbusRtuConnection(
            $rtuDevice,   // Device path
            9600,         // Baud rate
            'N',          // Parity (None)
            8,            // Data bits
            1,            // Stop bits
            $libraryPath  // Optional: Bibliothekspfad
        );
        $modbusRtu->setSlave($rtuSlaveId);
        $modbusRtu->connect();
        echo "RTU Connected to {$rtuDevice}\n";

        $inputRegisters = $modbusRtu->readInputRegisters(50, 2);
        echo "RTU Read Input Registers (50-51): ";
        print_r($inputRegisters);

        $modbusRtu->close();
        echo "RTU Connection closed.\n";

    } catch (ModbusException $e) {
        echo "RTU Error: " . get_class($e) . " - " . $e->getMessage() . "\n";
    } catch (\Exception $e) {
        echo "General RTU Error: " . $e->getMessage() . "\n";
    }
}

?>
```

## Fehlerbehandlung
Die Bibliothek wirft spezifische Exceptions, die von OpenLocker\PhpModbusFfi\Exceptions\ModbusException erben:

- ModbusConnectionException: Fehler beim Aufbau oder der Verwaltung der Verbindung.

- ModbusIOException: Fehler während Lese-/Schreiboperationen.

- ModbusConfigurationException: Fehler aufgrund fehlerhafter Konfiguration (z.B. fehlende IP, ungültiger Treiber).

Fangen Sie diese Exceptions in Ihrem Code mit try...catch-Blöcken ab.

## Entwicklungsumgebung (z.B. Docker/Sail)
- libmodbus-dev installieren: Fügen Sie RUN apt-get update && apt-get install -y libmodbus-dev && rm -rf /var/lib/apt/lists/* zum relevanten Dockerfile hinzu.

- FFI aktivieren: Fügen Sie eine custom.ini-Datei mit [FFI]\nffi.enable=true hinzu und kopieren Sie diese im Dockerfile nach /usr/local/etc/php/conf.d/.

- Container neu bauen: z.B. docker compose build --no-cache oder sail build --no-cache.

- Container neu starten: z.B. docker compose down && docker compose up -d oder sail down && sail up -d.

- PHP-Code: Verwenden Sie die ModbusConnection-Klasse mit FFI::cdef(...) (wie im aktuellen Entwicklungsstand des Pakets).

## Produktionsumgebung
- Sicherheit: Verwenden Sie ffi.enable = "preload" in der php.ini des Produktionsservers.

- libmodbus installieren: Installieren Sie nur die Laufzeitbibliothek (z.B. apt install libmodbus5 oder yum install libmodbus). Stellen Sie sicher, dass die Version zur Header-Datei passt, die für Preloading verwendet wird.

- Preload-Header: Erstellen Sie eine bereinigte Header-Datei (modbus_preload.h) nur mit den benötigten Definitionen (Funktionen, Typen, extern int errno;) und #define FFI_SCOPE "MODBUS_FFI_SCOPE". (Der Scope-Name kann angepasst werden). Legen Sie diese Datei auf dem Server ab (z.B. /etc/php/ffi/modbus_preload.h) und stellen Sie sicher, dass der PHP-Prozess Leserechte hat.

- php.ini konfigurieren: Setzen Sie ffi.preload auf den Pfad zur modbus_preload.h (z.B. ffi.preload = /etc/php/ffi/modbus_preload.h).

- PHP-Code anpassen: Ändern Sie den Konstruktor von ModbusConnection, um FFI::scope("MODBUS_FFI_SCOPE") anstelle von FFI::cdef(...) zu verwenden. Entfernen Sie die CDEF-Konstante. (Passen Sie den Scope-Namen im Code an den im Header an).

- PHP-Prozess neu starten: Starten Sie den PHP-Prozess (z.B. PHP-FPM, Apache mit mod_php) neu, damit die php.ini-Änderungen wirksam werden.

