#!/bin/bash
# Start Mailpit for email testing with wp-env

# Find the wp-env network (it has a hash in the name)
WPENV_NETWORK=$(docker network ls --format '{{.Name}}' | grep -E '^[a-f0-9]{32}_default$' | head -n 1)

if [ -z "$WPENV_NETWORK" ]; then
    echo "Error: Could not find wp-env network. Make sure wp-env is running."
    echo "Run: npx @wordpress/env start"
    exit 1
fi

echo "Found wp-env network: $WPENV_NETWORK"

# Stop existing Mailpit if running
if [ "$(docker ps -aq -f name=club-competitions-mailpit)" ]; then
    echo "Stopping existing Mailpit container..."
    docker stop club-competitions-mailpit 2>/dev/null
    docker rm club-competitions-mailpit 2>/dev/null
fi

# Start Mailpit
echo "Starting Mailpit..."
docker run -d \
    --name club-competitions-mailpit \
    --network "$WPENV_NETWORK" \
    -p 8026:8025 \
    -p 1026:1025 \
    -e MP_SMTP_AUTH_ACCEPT_ANY=1 \
    -e MP_SMTP_AUTH_ALLOW_INSECURE=1 \
    axllent/mailpit:latest

echo ""
echo "✅ Mailpit started successfully!"
echo ""
echo "Web UI:    http://localhost:8025"
echo "SMTP:      localhost:1025"
echo "Container: club-competitions-mailpit"
echo "Network:   $WPENV_NETWORK"
echo ""
echo "WordPress will now send emails to Mailpit automatically."
