#!/bin/bash
# Skript zum Erstellen und Einrichten einer virtuellen Python-Umgebung für Modbus
# und zum Ausführbarmachen der Python-Skripte

# Verzeichnis für die virtuelle Umgebung
VENV_DIR="/var/www/html/scripts/venv"

echo "=== Python virtuelle Umgebung einrichten ==="

# Prüfen, ob venv bereits existiert
if [ ! -d "$VENV_DIR" ]; then
    echo "Erstelle virtuelle Python-Umgebung..."
    python3 -m venv "$VENV_DIR"
else
    echo "Verwende bestehende virtuelle Python-Umgebung..."
fi

# Aktiviere die virtuelle Umgebung und installiere pymodbus
echo "Installiere pymodbus in der virtuellen Umgebung..."
source "$VENV_DIR/bin/activate"
pip install pymodbus
deactivate

echo "=== Python-Skripte ausführbar machen ==="

# Zum Stammverzeichnis des Projekts wechseln
cd /var/www/html

# Python-Skripte ausführbar machen
echo "Mache Python-Skripte ausführbar..."
chmod +x scripts/modbus_cli_venv.py
chmod +x scripts/setup_venv.sh

echo "=== Setup abgeschlossen ==="
echo "Virtuelle Python-Umgebung wurde mit pymodbus eingerichtet"
echo "Alle Python-Skripte wurden ausführbar gemacht"
echo ""
echo "Sie können das Modbus-Skript jetzt mit dem folgenden Befehl ausführen:"
echo "./vendor/bin/sail exec laravel.test /var/www/html/scripts/venv/bin/python /var/www/html/scripts/modbus_cli_venv.py [PARAMETER]"
