#!/bin/bash
# Start Mailpit for email testing with wp-env

# Find the network the running wp-env WordPress container is actually attached to.
# Detecting it from the container is robust across wp-env versions, whose network
# naming has changed over time (32-char hash prefix in older versions, the project
# directory name in newer ones). Falling back to the old hash-based match keeps this
# working if the container can't be inspected for some reason.
WPENV_WP_CONTAINER=$(docker ps --format '{{.Names}}' | grep -E '^photo-competition-manager-wordpress-[0-9]+$' | head -n 1)

if [ -n "$WPENV_WP_CONTAINER" ]; then
    WPENV_NETWORK=$(docker inspect "$WPENV_WP_CONTAINER" \
        --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' | head -n 1)
fi

if [ -z "$WPENV_NETWORK" ]; then
    WPENV_NETWORK=$(docker network ls --format '{{.Name}}' | grep -E '^[a-f0-9]{32}_default$' | head -n 1)
fi

if [ -z "$WPENV_NETWORK" ]; then
    echo "Error: Could not find wp-env network. Make sure wp-env is running."
    echo "Run: npx @wordpress/env start"
    exit 1
fi

echo "Found wp-env network: $WPENV_NETWORK"

# Stop existing Mailpit if running
if [ "$(docker ps -aq -f name=photo-competition-manager-mailpit)" ]; then
    echo "Stopping existing Mailpit container..."
    docker stop photo-competition-manager-mailpit 2>/dev/null
    docker rm photo-competition-manager-mailpit 2>/dev/null
fi

# Start Mailpit
echo "Starting Mailpit..."
docker run -d \
    --name photo-competition-manager-mailpit \
    --network "$WPENV_NETWORK" \
    -p 8026:8025 \
    -p 1026:1025 \
    -e MP_SMTP_AUTH_ACCEPT_ANY=1 \
    -e MP_SMTP_AUTH_ALLOW_INSECURE=1 \
    axllent/mailpit:latest

echo ""
echo "✅ Mailpit started successfully!"
echo ""
echo "Web UI:    http://localhost:8026"
echo "SMTP:      localhost:1025"
echo "Container: photo-competition-manager-mailpit"
echo "Network:   $WPENV_NETWORK"
echo ""
echo "WordPress will now send emails to Mailpit automatically."
