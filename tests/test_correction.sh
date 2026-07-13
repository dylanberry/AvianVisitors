#!/usr/bin/env bash
# Bird Up! - shell runner for correction.php / birdnet-api.php QA.
#
# This script runs tests/php/CorrectionEndpointTest.php, which itself creates a
# temporary BirdNET-Pi tree, starts a temporary php -S server, and exercises
# every correction action and every filtered aggregation via HTTP.
#
# PHP does not need to be installed locally: the script will automatically use
# Docker php:8.4-cli if PHP is unavailable.
#
# Usage:
#   ./tests/test_correction.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
EVIDENCE_DIR="$REPO_ROOT/.omo/evidence"
EVIDENCE_FILE="$EVIDENCE_DIR/task-7-incorrect-bird-identifications.txt"

mkdir -p "$EVIDENCE_DIR"

echo "============================================" | tee "$EVIDENCE_FILE"
echo "Bird Up! correction API test run" | tee -a "$EVIDENCE_FILE"
echo "Date: $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee -a "$EVIDENCE_FILE"
echo "Host PHP: $(which php 2>/dev/null || echo 'not installed')" | tee -a "$EVIDENCE_FILE"
echo "Docker: $(docker --version 2>/dev/null || echo 'not installed')" | tee -a "$EVIDENCE_FILE"
echo "============================================" | tee -a "$EVIDENCE_FILE"
echo "" | tee -a "$EVIDENCE_FILE"

cd "$REPO_ROOT"

# Optional: syntax-check the PHP files before running the integration test.
if command -v php >/dev/null 2>&1; then
    echo "[syntax] php -l avian/api/correction.php" | tee -a "$EVIDENCE_FILE"
    php -l avian/api/correction.php 2>&1 | tee -a "$EVIDENCE_FILE"
    echo "[syntax] php -l avian/api/birdnet-api.php" | tee -a "$EVIDENCE_FILE"
    php -l avian/api/birdnet-api.php 2>&1 | tee -a "$EVIDENCE_FILE"
    echo "[syntax] php -l tests/php/CorrectionEndpointTest.php" | tee -a "$EVIDENCE_FILE"
    php -l tests/php/CorrectionEndpointTest.php 2>&1 | tee -a "$EVIDENCE_FILE"
    echo "" | tee -a "$EVIDENCE_FILE"
fi

# Run the integration test harness.
FAILURES=0
if command -v php >/dev/null 2>&1; then
    echo "[run] php tests/php/CorrectionEndpointTest.php" | tee -a "$EVIDENCE_FILE"
    set +e
    php tests/php/CorrectionEndpointTest.php 2>&1 | tee -a "$EVIDENCE_FILE"
    FAILURES=${PIPESTATUS[0]}
    set -e
else
    echo "[run] Docker php:8.4-cli php tests/php/CorrectionEndpointTest.php" | tee -a "$EVIDENCE_FILE"
    set +e
    docker run --rm \
        -v "$REPO_ROOT:/app" \
        -w /app \
        php:8.4-cli \
        php tests/php/CorrectionEndpointTest.php 2>&1 | tee -a "$EVIDENCE_FILE"
    FAILURES=${PIPESTATUS[0]}
    set -e
fi

echo "" | tee -a "$EVIDENCE_FILE"
echo "============================================" | tee -a "$EVIDENCE_FILE"
if [ "$FAILURES" -eq 0 ]; then
    echo "All tests passed." | tee -a "$EVIDENCE_FILE"
else
    echo "Test run exited with $FAILURES failure(s)." | tee -a "$EVIDENCE_FILE"
fi
echo "============================================" | tee -a "$EVIDENCE_FILE"

exit "$FAILURES"
