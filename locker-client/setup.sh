#!/bin/bash

# Setup script for locker-client configuration

set -e

echo "=== Locker Client Setup ==="
echo ""

# Create directories
echo "Creating directories..."
mkdir -p config data

# Copy example configuration
if [ ! -f config/locker-config.yml ]; then
    echo "Creating config/locker-config.yml from example..."
    
    # Check if example file exists locally, otherwise download it
    if [ ! -f locker-config.yml.example ]; then
        echo "Downloading example configuration from GitHub..."
        wget -q https://raw.githubusercontent.com/Open-Locker/Open-Locker/main/locker-client/locker-config.yml.example -O locker-config.yml.example
        if [ $? -ne 0 ]; then
            echo "❌ Failed to download example configuration"
            exit 1
        fi
    fi
    
    cp locker-config.yml.example config/locker-config.yml
    echo "✓ Configuration file created"
    echo ""
    echo "⚠️  Please edit config/locker-config.yml with your settings:"
    echo "   - Update MQTT broker URL and credentials"
    echo "   - Configure Modbus port and clients"
else
    echo "✓ Configuration file already exists"
fi

echo ""

# Check for provisioning token
if [ ! -f data/.provisioning-state ]; then
    echo "⚠️  Locker is not provisioned"
    echo ""
    read -p "Do you have a provisioning token? (y/n): " has_token
    
    if [ "$has_token" = "y" ] || [ "$has_token" = "Y" ]; then
        read -p "Enter provisioning token: " token
        echo "$token" > config/provisioning-token
        echo "✓ Provisioning token saved to config/provisioning-token"
        echo "  (This file will be automatically deleted after provisioning)"
    else
        echo "  You can add the provisioning token later:"
        echo "  echo 'YOUR_TOKEN' > config/provisioning-token"
    fi
else
    echo "✓ Locker is already provisioned"
fi

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Next steps:"
echo "1. Review and edit config/locker-config.yml"
echo "2. Start the container with: docker-compose up -d"
echo "3. Check logs with: docker-compose logs -f"
echo ""
