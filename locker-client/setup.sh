#!/bin/bash

# Setup script for locker-client configuration

set -e

echo "=== Locker Client Setup ==="
echo ""

# Create directories
echo "Creating directories..."
mkdir -p config data

# Download docker-compose.yml if not present
if [ ! -f docker-compose.yml ]; then
    echo "Downloading docker-compose.yml from GitHub..."
    wget -q https://raw.githubusercontent.com/Open-Locker/Open-Locker/main/locker-client/docker-compose.yml -O docker-compose.yml
    if [ $? -ne 0 ]; then
        echo "❌ Failed to download docker-compose.yml"
        exit 1
    fi
    echo "✓ docker-compose.yml downloaded"
else
    echo "✓ docker-compose.yml already exists"
fi

echo ""

# Copy example environment file
if [ ! -f .env ]; then
    echo "Creating .env from example..."

    if [ ! -f .env.example ]; then
        echo "Downloading example environment file from GitHub..."
        wget -q https://raw.githubusercontent.com/Open-Locker/Open-Locker/main/locker-client/.env.example -O .env.example
        if [ $? -ne 0 ]; then
            echo "❌ Failed to download example environment file"
            exit 1
        fi
    fi

    cp .env.example .env
    echo "✓ Environment file created"
else
    echo "✓ .env already exists"
fi

echo ""

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
    echo "  Set PROVISIONING_TOKEN in .env before starting the container"
else
    echo "✓ Locker is already provisioned"
fi

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Next steps:"
echo "1. Review and edit .env"
echo "2. Review and edit config/locker-config.yml"
echo "3. Start the containers with: docker compose up -d"
echo "4. Check logs with: docker compose logs -f"
echo ""
