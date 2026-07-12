#!/usr/bin/env bash
# Deploy the latest AvianVisitors front-end + Caddy changes from this repo to
# the live Pi. Run from the repo root.
#
# This script needs a one-time, temporary passwordless sudoers entry on the
# Pi because the agent workstation cannot prompt for the Pi sudo password.
# If the entry is missing, the script prints the command to create it and exits.
set -euo pipefail

PI_HOST="192.168.86.50"
PI_USER="dylanberry"
PI_AVIAN_DIR="/home/dylanberry/BirdNET-Pi/avian"
SUDOERS_FILE="/etc/sudoers.d/avian-deploy"

if [ ! -f avian/frontend/index.html ]; then
    echo "Error: must be run from the repository root." >&2
    exit 1
fi

echo "Checking temporary sudoers on Pi (${PI_HOST})..."
if ! ssh -o BatchMode=yes -o ConnectTimeout=5 "${PI_USER}@${PI_HOST}" "test -f ${SUDOERS_FILE}"; then
    cat <<EOF >&2

Temporary sudoers file not found on the Pi.
Run the following on the Pi to grant passwordless sudo for this deploy only:

ssh ${PI_USER}@${PI_HOST}

echo '${PI_USER} ALL=(root) NOPASSWD: /usr/bin/cp /tmp/Caddyfile /etc/caddy/Caddyfile, /usr/bin/systemctl reload caddy, /usr/bin/rm -f ${SUDOERS_FILE}' | sudo tee ${SUDOERS_FILE} && sudo chmod 440 ${SUDOERS_FILE} && sudo visudo -c

Then run this script again. The sudoers file is removed automatically at the end.

EOF
    exit 1
fi

echo "Copying front-end files..."
scp -o BatchMode=yes avian/frontend/index.html avian/frontend/apt.js \
    "${PI_USER}@${PI_HOST}:${PI_AVIAN_DIR}/frontend/"

echo "Copying Caddy auth snippet..."
scp -o BatchMode=yes avian/forwarding/caddy-auth.caddy \
    "${PI_USER}@${PI_HOST}:${PI_AVIAN_DIR}/forwarding/"

echo "Copying live Caddyfile..."
scp -o BatchMode=yes docs/deployment/Caddyfile \
    "${PI_USER}@${PI_HOST}:/tmp/Caddyfile"

echo "Installing Caddyfile and reloading Caddy..."
ssh -o BatchMode=yes "${PI_USER}@${PI_HOST}" \
    "sudo cp /tmp/Caddyfile /etc/caddy/Caddyfile && sudo systemctl reload caddy && sudo rm -f ${SUDOERS_FILE}"

echo "Verifying..."
public_urls=(
    "http://${PI_HOST}/"
    "http://${PI_HOST}/avian/api/birdnet-api.php"
)
for url in "${public_urls[@]}"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    echo "  $url -> $code"
    if [ "$code" != "200" ]; then
        echo "Error: public endpoint returned $code" >&2
        exit 1
    fi
done

admin_urls=(
    "http://${PI_HOST}/avian/api/menu.php"
    "http://${PI_HOST}/avian/api/config.php"
    "http://${PI_HOST}/avian/api/birdnet-status.php"
)
for url in "${admin_urls[@]}"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    echo "  $url -> $code"
    if [ "$code" != "401" ]; then
        echo "Error: admin endpoint returned $code (expected 401)" >&2
        exit 1
    fi
done

echo "Deploy complete."
