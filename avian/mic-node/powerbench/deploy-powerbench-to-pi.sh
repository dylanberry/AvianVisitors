#!/usr/bin/env bash
# Deploy powerbench (experiment controller, :9559) to sb-birdnet-pi4.
# Run from the repository root. Pi is a deploy target, never a source of truth.
#
# NOT started by default: installing only. Enable with:
#   ssh dylanberry@192.168.86.50 'sudo systemctl enable --now powerbench'
# after placing ~/.powerbench/ntfy-token (and optionally grafana-token).
set -euo pipefail

PI_HOST="192.168.86.50"
PI_USER="dylanberry"
PB_DIR="avian/mic-node/powerbench"

if [ ! -f "${PB_DIR}/powerbench.py" ]; then
    echo "Error: must be run from the repository root." >&2
    exit 1
fi

ssh_() { ssh -o BatchMode=yes -o ConnectTimeout=5 "${PI_USER}@${PI_HOST}" "$@"; }

echo "==> powerbench.py -> /usr/local/bin"
scp -o BatchMode=yes "${PB_DIR}/powerbench.py" "${PI_USER}@${PI_HOST}:/tmp/powerbench.py"
ssh_ "sudo cp /tmp/powerbench.py /usr/local/bin/powerbench.py && sudo chmod +x /usr/local/bin/powerbench.py && rm -f /tmp/powerbench.py"

echo "==> config + schedule -> ~/.powerbench (preserving state/tokens)"
ssh_ "mkdir -p /home/dylanberry/.powerbench"
scp -o BatchMode=yes "${PB_DIR}/powerbench.json" "${PB_DIR}/experiments.json" \
    "${PI_USER}@${PI_HOST}:/tmp/"
ssh_ "cp /tmp/powerbench.json /tmp/experiments.json /home/dylanberry/.powerbench/ && rm -f /tmp/powerbench.json /tmp/experiments.json"

echo "==> systemd unit (installed, not enabled)"
scp -o BatchMode=yes "${PB_DIR}/powerbench.service" "${PI_USER}@${PI_HOST}:/tmp/powerbench.service"
ssh_ "sudo cp /tmp/powerbench.service /etc/systemd/system/powerbench.service && rm -f /tmp/powerbench.service && sudo systemctl daemon-reload"

if ssh_ "systemctl is-active --quiet powerbench"; then
    echo "==> powerbench is running; restarting to pick up new code"
    ssh_ "sudo systemctl restart powerbench"
    ssh_ "curl -sf -o /dev/null http://127.0.0.1:9559/metrics" \
        && echo "    :9559 metrics OK" \
        || { echo "    :9559 metrics FAILED" >&2; exit 1; }
else
    echo "==> powerbench installed but not running (enable manually when ready)"
fi
