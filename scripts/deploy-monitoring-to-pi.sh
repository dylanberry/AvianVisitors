#!/usr/bin/env bash
# Deploy the Bird Up! → Prometheus exporter stack to sb-birdnet-pi4:
#
#   1. node-telemetry-exporter.py  -> /usr/local/bin (birdup-exporter.service, :9558)
#   2. Caddyfile                   -> /etc/caddy (adds :2020 metrics proxy,
#      loopback :2021 php-fpm status route, and file access logs)
#   3. php-fpm status              -> enable pm.status_path=/status on pool www
#   4. mtail                       -> /usr/local/bin + /etc/mtail/caddy-logs.mtail (:3903)
#   5. php-fpm_exporter            -> /usr/local/bin (:9253) -> 127.0.0.1:2021/status?json
#
# Run from the repo root. The Pi's dylanberry user has passwordless sudo; no
# sudoers file dance needed (unlike deploy-to-pi.sh). All files here are the
# repo copies — the Pi is a deploy target, never a source of truth.
set -euo pipefail

PI_HOST="192.168.86.50"
PI_USER="dylanberry"
MIC_NODE_DIR="avian/mic-node"
MTALL_VERSION="v3.0.8"
PHPFPM_EXPORTER_VERSION="v2.2.0"

if [ ! -f "${MIC_NODE_DIR}/node-telemetry-exporter.py" ]; then
    echo "Error: must be run from the repository root." >&2
    exit 1
fi

ssh_() { ssh -o BatchMode=yes -o ConnectTimeout=5 "${PI_USER}@${PI_HOST}" "$@"; }

echo "==> 1/5 exporter + systemd units"
scp -o BatchMode=yes "${MIC_NODE_DIR}/node-telemetry-exporter.py" \
    "${PI_USER}@${PI_HOST}:/tmp/node-telemetry-exporter.py"
ssh_ "sudo cp /tmp/node-telemetry-exporter.py /usr/local/bin/node-telemetry-exporter.py && sudo chmod +x /usr/local/bin/node-telemetry-exporter.py && rm -f /tmp/node-telemetry-exporter.py"

for unit in birdup-exporter birdup-mtail birdup-phpfpm-exporter; do
    scp -o BatchMode=yes "${MIC_NODE_DIR}/${unit}.service" "${PI_USER}@${PI_HOST}:/tmp/${unit}.service"
    ssh_ "sudo cp /tmp/${unit}.service /etc/systemd/system/${unit}.service && rm -f /tmp/${unit}.service"
done

echo "==> 2/5 Caddyfile (validate candidate before installing)"
# Prepare the access log first: the packaged unit runs as user caddy, and a
# root-owned 0600 file would fail the reload (permission denied on open).
ssh_ "sudo mkdir -p /var/log/caddy && sudo touch /var/log/caddy/access.log && sudo chown caddy:caddy /var/log/caddy/access.log && sudo chmod 0644 /var/log/caddy/access.log"
scp -o BatchMode=yes docs/deployment/Caddyfile "${PI_USER}@${PI_HOST}:/tmp/Caddyfile.new"
ssh_ "sudo cp /tmp/Caddyfile.new /etc/caddy/Caddyfile.new && sudo caddy validate --config /etc/caddy/Caddyfile.new && sudo cp /etc/caddy/Caddyfile.new /etc/caddy/Caddyfile && sudo systemctl reload caddy && sudo rm -f /etc/caddy/Caddyfile.new /tmp/Caddyfile.new"

echo "==> 3/5 php-fpm status path (pool www)"
if ssh_ "grep -q '^;pm.status_path = /status' /etc/php/8.4/fpm/pool.d/www.conf"; then
    ssh_ "sudo sed -i 's|^;pm.status_path = /status|pm.status_path = /status|' /etc/php/8.4/fpm/pool.d/www.conf && sudo systemctl restart php8.4-fpm"
    echo "    enabled pm.status_path in www.conf, restarted php8.4-fpm"
elif ssh_ "grep -q '^pm.status_path = /status' /etc/php/8.4/fpm/pool.d/www.conf"; then
    echo "    pm.status_path already enabled; skipping"
else
    echo "Warning: could not find pm.status_path line in www.conf" >&2
fi

echo "==> 4/5 mtail (${MTALL_VERSION})"
if ! ssh_ "test -x /usr/local/bin/mtail"; then
    ssh_ "cd /tmp && for i in 1 2 3; do curl -sL -o mtail.tgz https://github.com/google/mtail/releases/download/${MTALL_VERSION}/mtail_${MTALL_VERSION#v}_linux_arm64.tar.gz; if tar tzf mtail.tgz >/dev/null 2>&1; then break; fi; rm -f mtail.tgz; sleep 3; done && tar xzf mtail.tgz mtail && sudo cp mtail /usr/local/bin/mtail && sudo chmod +x /usr/local/bin/mtail && rm -f mtail.tgz mtail"
    echo "    installed mtail"
else
    echo "    mtail already installed"
fi
scp -o BatchMode=yes "${MIC_NODE_DIR}/caddy-logs.mtail" "${PI_USER}@${PI_HOST}:/tmp/caddy-logs.mtail"
ssh_ "sudo mkdir -p /etc/mtail && sudo cp /tmp/caddy-logs.mtail /etc/mtail/caddy-logs.mtail && rm -f /tmp/caddy-logs.mtail"

echo "==> 5/5 php-fpm_exporter (${PHPFPM_EXPORTER_VERSION})"
if ! ssh_ "test -x /usr/local/bin/php-fpm_exporter"; then
    ssh_ "cd /tmp && for i in 1 2 3; do curl -sL -o phpfpm.tgz https://github.com/hipages/php-fpm_exporter/releases/download/${PHPFPM_EXPORTER_VERSION}/php-fpm_exporter_${PHPFPM_EXPORTER_VERSION#v}_linux_arm64.tar.gz; if tar tzf phpfpm.tgz >/dev/null 2>&1; then break; fi; rm -f phpfpm.tgz; sleep 3; done && tar xzf phpfpm.tgz php-fpm_exporter && sudo cp php-fpm_exporter /usr/local/bin/php-fpm_exporter && sudo chmod +x /usr/local/bin/php-fpm_exporter && rm -f phpfpm.tgz php-fpm_exporter"
    echo "    installed php-fpm_exporter"
else
    echo "    php-fpm_exporter already installed"
fi

echo "==> 6/6 node_exporter (apt, :9100)"
if ! ssh_ "systemctl is-active --quiet prometheus-node-exporter"; then
    ssh_ "sudo apt-get install -y -qq prometheus-node-exporter"
else
    echo "    node_exporter already running"
fi

echo "==> enabling services"
ssh_ "sudo systemctl daemon-reload && sudo systemctl enable birdup-exporter birdup-mtail birdup-phpfpm-exporter && sudo systemctl restart birdup-exporter birdup-mtail birdup-phpfpm-exporter"

echo "==> verifying"
checks=(
    "9558|birdup-exporter (node telemetry)|curl -sf -o /dev/null http://127.0.0.1:9558/metrics"
    "2020|caddy metrics proxy|curl -sf http://127.0.0.1:2020/metrics | grep -q '^caddy_'"
    "9253|php-fpm exporter|curl -sf http://127.0.0.1:9253/metrics | grep -q '^phpfpm_'"
    "3903|mtail|curl -sf -o /dev/null http://127.0.0.1:3903/metrics"
    "9100|node_exporter|curl -sf -o /dev/null http://127.0.0.1:9100/metrics"
)
rc=0
for entry in "${checks[@]}"; do
    port="${entry%%|*}"
    desc="${entry#*|}"
    curl_cmd="${entry#*|*|}"
    if ssh_ "${curl_cmd}"; then
        echo "    :${port} ${desc} OK"
    else
        echo "    :${port} ${desc} FAILED" >&2
        rc=1
    fi
done
exit $rc