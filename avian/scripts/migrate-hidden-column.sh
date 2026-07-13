#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Safe migration to add the Hidden column to the BirdNET-Pi detections table.
# =============================================================================
#
# WARNING: scripts/createdb.sh drops and recreates the detections table. NEVER
# run createdb.sh on a live Pi with existing data. Use this script instead.
#
# Usage:
#   ./migrate-hidden-column.sh [DB_PATH]
#
# Default DB_PATH: $HOME/BirdNET-Pi/scripts/birds.db
#
# This script:
#   1. Backs up the database.
#   2. Verifies SQLite supports ALTER TABLE ADD COLUMN (>= 3.1.3).
#   3. Adds "Hidden INTEGER DEFAULT 0 NOT NULL" only if it is missing.
#   4. Sets PRAGMA user_version = 1 when the migration is complete.
# =============================================================================

DB_PATH="${1:-$HOME/BirdNET-Pi/scripts/birds.db}"

check_sqlite_version() {
    local min_version="3.1.3"
    local sqlite_version
    sqlite_version=$(sqlite3 "" "SELECT sqlite_version();")

    # sort -C -V returns 0 if the first string sorts before or equal to the
    # second; -C means "silent check". We want min_version <= sqlite_version.
    if ! printf '%s\n%s\n' "$min_version" "$sqlite_version" | sort -C -V; then
        echo "Error: SQLite $sqlite_version is too old; ALTER TABLE ADD COLUMN requires SQLite >= $min_version" >&2
        exit 1
    fi
    echo "SQLite version: $sqlite_version (OK)"
}

main() {
    if [[ ! -f "$DB_PATH" ]]; then
        echo "Error: database not found at $DB_PATH" >&2
        exit 1
    fi

    check_sqlite_version

    local timestamp
    timestamp=$(date +%Y%m%d%H%M%S)
    local backup_path="${DB_PATH}.bak.${timestamp}"

    cp "$DB_PATH" "$backup_path"
    echo "Backed up database: $backup_path"

    local column_exists
    column_exists=$(sqlite3 "$DB_PATH" "SELECT COUNT(*) FROM pragma_table_info('detections') WHERE name = 'Hidden';")

    local user_version
    user_version=$(sqlite3 "$DB_PATH" "PRAGMA user_version;")

    if [[ "$column_exists" -eq 1 ]]; then
        echo "Hidden column already exists; no migration needed."
        if [[ "$user_version" -eq 0 ]]; then
            sqlite3 "$DB_PATH" "PRAGMA user_version = 1;"
            echo "Set PRAGMA user_version = 1."
        fi
        exit 0
    fi

    sqlite3 "$DB_PATH" "ALTER TABLE detections ADD COLUMN Hidden INTEGER DEFAULT 0 NOT NULL;"
    sqlite3 "$DB_PATH" "PRAGMA user_version = 1;"
    echo "Migration complete: added Hidden column and set PRAGMA user_version = 1."
}

main "$@"
